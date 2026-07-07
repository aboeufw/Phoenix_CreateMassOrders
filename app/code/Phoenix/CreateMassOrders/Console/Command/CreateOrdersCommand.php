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
    private const FILE_COLUMN_CUSTOMER_ERP_ID = 'customer_erp_id';
    private const FILE_COLUMN_SKUS = 'skus';
    private const FILE_COLUMN_PAYMENT_METHOD = 'payment_method';

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
            'Chemin vers un fichier plat decrivant les commandes a generer (une ligne = une commande). '
            . 'Colonnes separees par ";" avec en-tete : customer_erp_id;skus;payment_method '
            . '(skus separes par des virgules, payment_method optionnel). Rend --count, --customer-erp-id et --sku inutilises.'
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

        [$products, $missingSkus] = $this->orderGenerator->loadProductsBySku($skus);
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
            count($products)
        ));

        $progressBar = new ProgressBar($output, $count);
        $progressBar->start();

        $created = [];
        $errors = [];
        $detailedSignatures = [];

        for ($i = 1; $i <= $count; $i++) {
            try {
                $order = $this->orderGenerator->createOrder($customer, $products, $paymentMethod);
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
     * Genere une commande par ligne d'un fichier plat (customer_erp_id;skus;payment_method).
     */
    private function executeFromFile(InputInterface $input, OutputInterface $output, string $filePath): int
    {
        $fallbackPaymentMethod = trim((string) $input->getOption(self::OPTION_PAYMENT_METHOD))
            ?: self::DEFAULT_PAYMENT_METHOD;

        try {
            $rows = $this->readOrderRows($filePath);
        } catch (RuntimeException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        if (empty($rows)) {
            $output->writeln('<error>Aucune ligne de commande exploitable dans le fichier.</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Generation de %d commande(s) depuis "%s" (1 commande par ligne, transporteur : transporter_transporter)</info>',
            count($rows),
            $filePath
        ));

        $progressBar = new ProgressBar($output, count($rows));
        $progressBar->start();

        $created = [];
        $errors = [];
        $detailedSignatures = [];

        foreach ($rows as $row) {
            $lineLabel = sprintf('Ligne %d (client ERP %s)', $row['line'], $row['customer_erp_id'] ?: '?');

            try {
                if ($row['customer_erp_id'] === '') {
                    throw new LocalizedException(__('Colonne customer_erp_id vide.'));
                }
                if (empty($row['skus'])) {
                    throw new LocalizedException(__('Colonne skus vide.'));
                }

                $paymentMethod = $row['payment_method'] !== '' ? $row['payment_method'] : $fallbackPaymentMethod;

                $customer = $this->orderGenerator->getCustomerByErpId($row['customer_erp_id']);
                $this->orderGenerator->assertPaymentMethodIsActive($paymentMethod);

                [$products, $missingSkus] = $this->orderGenerator->loadProductsBySku($row['skus']);
                if (!empty($missingSkus)) {
                    throw new LocalizedException(__('SKU introuvable(s) : %1', implode(', ', $missingSkus)));
                }

                $order = $this->orderGenerator->createOrder($customer, $products, $paymentMethod);
                $created[] = $order->getIncrementId();
            } catch (Throwable $e) {
                $signature = get_class($e) . ':' . $e->getMessage();
                $detailed = !isset($detailedSignatures[$signature]);
                $detailedSignatures[$signature] = true;
                $errors[] = sprintf('%s : %s', $lineLabel, $e->getMessage())
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
     * @return array<int, array{line: int, customer_erp_id: string, skus: string[], payment_method: string}>
     */
    private function readOrderRows(string $filePath): array
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

        $header = array_map(static fn (string $column): string => strtolower(trim($column)), $header);
        $requiredColumns = [self::FILE_COLUMN_CUSTOMER_ERP_ID, self::FILE_COLUMN_SKUS];
        foreach ($requiredColumns as $requiredColumn) {
            if (!in_array($requiredColumn, $header, true)) {
                fclose($handle);
                throw new RuntimeException(sprintf(
                    'Colonne obligatoire manquante dans l\'en-tete du fichier : "%s". '
                    . 'En-tete attendu : customer_erp_id;skus;payment_method',
                    $requiredColumn
                ));
            }
        }

        $rows = [];
        $lineNumber = 1;

        while (($data = fgetcsv($handle, 0, self::FILE_DELIMITER)) !== false) {
            $lineNumber++;

            if ($data === [null]) {
                continue;
            }

            $data = array_slice(array_pad($data, count($header), null), 0, count($header));
            $columns = array_combine($header, $data);

            $customerErpId = trim((string) ($columns[self::FILE_COLUMN_CUSTOMER_ERP_ID] ?? ''));
            $skus = array_values(array_filter(array_map(
                'trim',
                explode(',', (string) ($columns[self::FILE_COLUMN_SKUS] ?? ''))
            )));
            $paymentMethod = trim((string) ($columns[self::FILE_COLUMN_PAYMENT_METHOD] ?? ''));

            if ($customerErpId === '' && empty($skus)) {
                continue;
            }

            $rows[] = [
                'line' => $lineNumber,
                'customer_erp_id' => $customerErpId,
                'skus' => $skus,
                'payment_method' => $paymentMethod,
            ];
        }

        fclose($handle);

        return $rows;
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
