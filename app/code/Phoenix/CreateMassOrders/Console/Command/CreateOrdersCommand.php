<?php

declare(strict_types=1);

namespace Phoenix\CreateMassOrders\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Phoenix\CreateMassOrders\Model\OrderGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use RuntimeException;
use Throwable;

class CreateOrdersCommand extends Command
{
    private const OPTION_COUNT = 'count';
    private const OPTION_CUSTOMER_ERP_ID = 'customer-erp-id';
    private const OPTION_SKU = 'sku';
    private const OPTION_PAYMENT_METHOD = 'payment-method';
    private const OPTION_FILE = 'file';

    private const DEFAULT_PAYMENT_METHOD = 'checkmo';
    private const FILE_DELIMITER = ';';
    private const FILE_COLUMN_ORDER = 'order';
    private const FILE_COLUMN_CUSTOMER_ERP_ID = 'customer_erp_id';
    private const FILE_COLUMN_SKU = 'sku';
    private const FILE_COLUMN_QTY = 'qty';
    private const FILE_COLUMN_PAYMENT_METHOD = 'payment_method';

    private const FILE_HEADER_HINT = 'order;customer_erp_id;sku;qty;payment_method';

    public function __construct(
        private readonly OrderGenerator $orderGenerator,
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('phoenix:createmassorders:generate');
        $this->setDescription(
            'Genere en masse des commandes de test pour un client donne (environnement de recette)'
        );
        $this->addOption(
            self::OPTION_COUNT,
            'c',
            InputOption::VALUE_REQUIRED,
            'Nombre de commandes a generer'
        );
        $this->addOption(
            self::OPTION_CUSTOMER_ERP_ID,
            'e',
            InputOption::VALUE_REQUIRED,
            'Numero client ERP (attribut client wesco_customer_erp_id) a utiliser pour la facturation et la livraison'
        );
        $this->addOption(
            self::OPTION_SKU,
            's',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'SKU a inclure dans chaque commande (repeter l\'option pour plusieurs SKU, quantite 1 par SKU)'
        );
        $this->addOption(
            self::OPTION_PAYMENT_METHOD,
            'p',
            InputOption::VALUE_REQUIRED,
            'Code du mode de paiement a utiliser (valeur par defaut utilisee en mode --file si la colonne payment_method est vide)',
            self::DEFAULT_PAYMENT_METHOD
        );
        $this->addOption(
            self::OPTION_FILE,
            'f',
            InputOption::VALUE_REQUIRED,
            'Chemin vers un fichier plat decrivant les commandes a generer (une ligne = un produit, '
            . 'les lignes sont regroupees par identifiant de commande). '
            . 'Colonnes separees par ";" avec en-tete : ' . self::FILE_HEADER_HINT . ' '
            . '(qty optionnel, defaut 1 ; payment_method optionnel). '
            . 'Rend --count, --customer-erp-id et --sku inutilises.'
        );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (LocalizedException) {
            // Area deja definie par un contexte appelant, on ignore.
        }

        $filePath = trim((string) $input->getOption(self::OPTION_FILE));
        if ($filePath !== '') {
            return $this->executeFromFile($input, $output, $filePath);
        }

        $count = (int) $input->getOption(self::OPTION_COUNT);
        $erpId = trim((string) $input->getOption(self::OPTION_CUSTOMER_ERP_ID));
        $skus = array_values(array_unique(array_filter(array_map('trim', $input->getOption(self::OPTION_SKU)))));
        $paymentMethod = trim((string) $input->getOption(self::OPTION_PAYMENT_METHOD)) ?: self::DEFAULT_PAYMENT_METHOD;

        if ($count < 1) {
            $output->writeln('<error>L\'option --count doit etre un entier superieur a 0.</error>');
            return Command::FAILURE;
        }

        if ($erpId === '') {
            $output->writeln('<error>L\'option --customer-erp-id est obligatoire.</error>');
            return Command::FAILURE;
        }

        if (empty($skus)) {
            $output->writeln('<error>Au moins un --sku est obligatoire.</error>');
            return Command::FAILURE;
        }

        try {
            $customer = $this->orderGenerator->getCustomerByErpId($erpId);
        } catch (Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        try {
            $this->orderGenerator->assertPaymentMethodIsActive($paymentMethod);
        } catch (LocalizedException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        $skuQuantities = array_map(static fn (string $sku): array => ['sku' => $sku, 'qty' => 1.0], $skus);
        [$items, $missingSkus] = $this->orderGenerator->loadProductsWithQuantities($skuQuantities);
        if (!empty($missingSkus)) {
            $output->writeln(sprintf(
                '<error>SKU introuvable(s), aucune commande n\'a ete creee : %s</error>',
                implode(', ', $missingSkus)
            ));
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Generation de %d commande(s) pour le client ERP "%s" (paiement : %s, transporteur : transporter_transporter, %d SKU par commande)</info>',
            $count,
            $erpId,
            $paymentMethod,
            count($items)
        ));

        $progressBar = new ProgressBar($output, $count);
        $progressBar->start();

        $created = [];
        $errors = [];
        $detailedSignatures = [];

        for ($i = 1; $i <= $count; $i++) {
            try {
                $order = $this->orderGenerator->createOrder($customer, $items, $paymentMethod);
                $created[] = $order->getIncrementId();
            } catch (Throwable $e) {
                $signature = get_class($e) . ':' . $e->getMessage();
                $detailed = !isset($detailedSignatures[$signature]);
                $detailedSignatures[$signature] = true;
                $errors[] = sprintf('Commande #%d : %s', $i, $e->getMessage())
                    . ($detailed ? "\n" . $this->formatCauseChain($e) : '');
            }
            $progressBar->advance();
        }

        $progressBar->finish();
        $output->writeln('');
        $output->writeln(sprintf('<info>%d commande(s) creee(s) avec succes.</info>', count($created)));

        if (!empty($created)) {
            $output->writeln(implode(', ', $created));
        }

        if (!empty($errors)) {
            $output->writeln(sprintf('<error>%d erreur(s) rencontree(s) :</error>', count($errors)));
            foreach ($errors as $error) {
                $output->writeln(sprintf('<error>- %s</error>', $error));
            }
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Genere une commande par identifiant de commande present dans le fichier plat
     * (order;customer_erp_id;sku;qty;payment_method) : toutes les lignes partageant
     * le meme "order" sont regroupees dans une seule commande, a raison d'une ligne
     * par reference produit.
     */
    private function executeFromFile(InputInterface $input, OutputInterface $output, string $filePath): int
    {
        $fallbackPaymentMethod = trim((string) $input->getOption(self::OPTION_PAYMENT_METHOD))
            ?: self::DEFAULT_PAYMENT_METHOD;

        try {
            $groups = $this->readOrderGroups($filePath);
        } catch (RuntimeException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        if (empty($groups)) {
            $output->writeln('<error>Aucune ligne de commande exploitable dans le fichier.</error>');
            return Command::FAILURE;
        }

        $lineCount = array_sum(array_map(static fn (array $group): int => count($group['lines']), $groups));

        $output->writeln(sprintf(
            '<info>Generation de %d commande(s) depuis "%s" (%d ligne(s) regroupee(s) par identifiant de commande, transporteur : transporter_transporter)</info>',
            count($groups),
            $filePath,
            $lineCount
        ));

        $progressBar = new ProgressBar($output, count($groups));
        $progressBar->start();

        $created = [];
        $errors = [];
        $detailedSignatures = [];

        foreach ($groups as $group) {
            $groupLabel = sprintf(
                'Commande "%s" (lignes %s)',
                $group['order'] !== '' ? $group['order'] : '?',
                $this->formatLineNumbers($group['lines'])
            );

            try {
                $orderData = $this->buildOrderData($group, $fallbackPaymentMethod);

                $customer = $this->orderGenerator->getCustomerByErpId($orderData['customer_erp_id']);
                $this->orderGenerator->assertPaymentMethodIsActive($orderData['payment_method']);

                [$items, $missingSkus] = $this->orderGenerator->loadProductsWithQuantities(
                    $orderData['sku_quantities']
                );
                if (!empty($missingSkus)) {
                    throw new LocalizedException(__('SKU introuvable(s) : %1', implode(', ', $missingSkus)));
                }

                $order = $this->orderGenerator->createOrder($customer, $items, $orderData['payment_method']);
                $created[$group['order']] = $order->getIncrementId();
            } catch (Throwable $e) {
                $signature = get_class($e) . ':' . $e->getMessage();
                $detailed = !isset($detailedSignatures[$signature]);
                $detailedSignatures[$signature] = true;
                $errors[] = sprintf('%s : %s', $groupLabel, $e->getMessage())
                    . ($detailed ? "\n" . $this->formatCauseChain($e) : '');
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $output->writeln('');
        $output->writeln(sprintf('<info>%d commande(s) creee(s) avec succes.</info>', count($created)));

        foreach ($created as $sourceOrder => $incrementId) {
            $output->writeln(sprintf('  %s -> %s', $sourceOrder, $incrementId));
        }

        if (!empty($errors)) {
            $output->writeln(sprintf('<error>%d erreur(s) rencontree(s) :</error>', count($errors)));
            foreach ($errors as $error) {
                $output->writeln(sprintf('<error>- %s</error>', $error));
            }
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Valide un groupe de lignes partageant le meme identifiant de commande et en
     * deduit les donnees necessaires a la creation de la commande. Les quantites de
     * lignes portant le meme SKU sont cumulees.
     *
     * @param array{order: string, lines: array<int, array{
     *     line: int,
     *     customer_erp_id: string,
     *     sku: string,
     *     qty: string,
     *     payment_method: string
     * }>} $group
     * @return array{
     *     customer_erp_id: string,
     *     sku_quantities: array<int, array{sku: string, qty: float}>,
     *     payment_method: string
     * }
     * @throws LocalizedException
     */
    private function buildOrderData(array $group, string $fallbackPaymentMethod): array
    {
        if ($group['order'] === '') {
            throw new LocalizedException(__('Colonne %1 vide.', self::FILE_COLUMN_ORDER));
        }

        $customerErpId = '';
        $paymentMethod = '';
        $quantities = [];

        foreach ($group['lines'] as $line) {
            if ($line['customer_erp_id'] === '') {
                throw new LocalizedException(__(
                    'Colonne %1 vide sur la ligne %2.',
                    self::FILE_COLUMN_CUSTOMER_ERP_ID,
                    $line['line']
                ));
            }
            if ($customerErpId === '') {
                $customerErpId = $line['customer_erp_id'];
            } elseif ($customerErpId !== $line['customer_erp_id']) {
                throw new LocalizedException(__(
                    'Numeros client ERP differents pour un meme identifiant de commande : "%1" et "%2" (ligne %3).',
                    $customerErpId,
                    $line['customer_erp_id'],
                    $line['line']
                ));
            }

            if ($line['payment_method'] !== '') {
                if ($paymentMethod === '') {
                    $paymentMethod = $line['payment_method'];
                } elseif ($paymentMethod !== $line['payment_method']) {
                    throw new LocalizedException(__(
                        'Modes de paiement differents pour un meme identifiant de commande : "%1" et "%2" (ligne %3).',
                        $paymentMethod,
                        $line['payment_method'],
                        $line['line']
                    ));
                }
            }

            if ($line['sku'] === '') {
                throw new LocalizedException(__(
                    'Colonne %1 vide sur la ligne %2.',
                    self::FILE_COLUMN_SKU,
                    $line['line']
                ));
            }

            $qty = $this->parseQty($line['qty'], $line['line']);
            $quantities[$line['sku']] = ($quantities[$line['sku']] ?? 0.0) + $qty;
        }

        if (empty($quantities)) {
            throw new LocalizedException(__('Aucune ligne produit exploitable pour cette commande.'));
        }

        $skuQuantities = [];
        foreach ($quantities as $sku => $qty) {
            $skuQuantities[] = ['sku' => (string) $sku, 'qty' => $qty];
        }

        return [
            'customer_erp_id' => $customerErpId,
            'sku_quantities' => $skuQuantities,
            'payment_method' => $paymentMethod !== '' ? $paymentMethod : $fallbackPaymentMethod,
        ];
    }

    /**
     * Quantite d'une ligne produit : vide vaut 1.
     *
     * @throws LocalizedException
     */
    private function parseQty(string $raw, int $lineNumber): float
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 1.0;
        }

        $raw = str_replace(',', '.', $raw);
        if (!is_numeric($raw)) {
            throw new LocalizedException(__(
                'Quantite invalide "%1" sur la ligne %2 (nombre attendu).',
                $raw,
                $lineNumber
            ));
        }

        $qty = (float) $raw;
        if ($qty <= 0) {
            throw new LocalizedException(__(
                'Quantite invalide "%1" sur la ligne %2 (doit etre superieure a 0).',
                $raw,
                $lineNumber
            ));
        }

        return $qty;
    }

    /**
     * Lit le fichier plat et regroupe les lignes par identifiant de commande,
     * en conservant l'ordre d'apparition dans le fichier.
     *
     * @return array<int, array{order: string, lines: array<int, array{
     *     line: int,
     *     customer_erp_id: string,
     *     sku: string,
     *     qty: string,
     *     payment_method: string
     * }>}>
     */
    private function readOrderGroups(string $filePath): array
    {
        if (!is_readable($filePath)) {
            throw new RuntimeException(sprintf('Fichier introuvable ou illisible : %s', $filePath));
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Impossible d\'ouvrir le fichier : %s', $filePath));
        }

        $header = fgetcsv($handle, 0, self::FILE_DELIMITER);
        if ($header === false) {
            fclose($handle);
            throw new RuntimeException('Le fichier est vide.');
        }

        $header = array_map(
            static fn ($column): string => strtolower(trim((string) $column)),
            $header
        );
        // Un BOM UTF-8 en tete de fichier collerait a la premiere colonne.
        if (isset($header[0])) {
            $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
        }

        $requiredColumns = [
            self::FILE_COLUMN_ORDER,
            self::FILE_COLUMN_CUSTOMER_ERP_ID,
            self::FILE_COLUMN_SKU,
        ];
        foreach ($requiredColumns as $requiredColumn) {
            if (!in_array($requiredColumn, $header, true)) {
                fclose($handle);
                throw new RuntimeException(sprintf(
                    'Colonne obligatoire manquante dans l\'en-tete du fichier : "%s". En-tete attendu : %s',
                    $requiredColumn,
                    self::FILE_HEADER_HINT
                ));
            }
        }

        $groups = [];
        $lineNumber = 1;

        while (($data = fgetcsv($handle, 0, self::FILE_DELIMITER)) !== false) {
            $lineNumber++;

            if ($data === [null]) {
                continue;
            }

            $data = array_slice(array_pad($data, count($header), null), 0, count($header));
            $columns = array_combine($header, $data);

            $line = [
                'line' => $lineNumber,
                'customer_erp_id' => trim((string) ($columns[self::FILE_COLUMN_CUSTOMER_ERP_ID] ?? '')),
                'sku' => trim((string) ($columns[self::FILE_COLUMN_SKU] ?? '')),
                'qty' => trim((string) ($columns[self::FILE_COLUMN_QTY] ?? '')),
                'payment_method' => trim((string) ($columns[self::FILE_COLUMN_PAYMENT_METHOD] ?? '')),
            ];
            $orderReference = trim((string) ($columns[self::FILE_COLUMN_ORDER] ?? ''));

            if ($orderReference === ''
                && $line['customer_erp_id'] === ''
                && $line['sku'] === ''
                && $line['qty'] === ''
            ) {
                continue;
            }

            // Prefixe pour ne pas confondre une reference numerique avec un index de tableau.
            $key = 'o:' . $orderReference;
            if (!isset($groups[$key])) {
                $groups[$key] = ['order' => $orderReference, 'lines' => []];
            }
            $groups[$key]['lines'][] = $line;
        }

        fclose($handle);

        return array_values($groups);
    }

    /**
     * @param array<int, array{line: int}> $lines
     */
    private function formatLineNumbers(array $lines): string
    {
        return implode(', ', array_map(static fn (array $line): int => $line['line'], $lines));
    }

    /**
     * Deroule la chaine d'exceptions (previous) pour afficher classe/fichier/ligne
     * de la cause reelle, masquee par defaut derriere le message de plus haut niveau.
     */
    private function formatCauseChain(Throwable $e): string
    {
        $lines = [];
        $current = $e;
        $depth = 0;

        while ($current !== null) {
            $lines[] = sprintf(
                '    %s[%s] %s (%s:%d)',
                str_repeat('  ', $depth),
                get_class($current),
                $current->getMessage(),
                $current->getFile(),
                $current->getLine()
            );
            $current = $current->getPrevious();
            $depth++;
        }

        return implode("\n", $lines);
    }
}
