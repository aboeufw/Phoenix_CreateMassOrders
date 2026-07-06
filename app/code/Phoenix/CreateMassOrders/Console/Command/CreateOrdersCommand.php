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
use Throwable;

class CreateOrdersCommand extends Command
{
    private const OPTION_COUNT = 'count';
    private const OPTION_CUSTOMER_EMAIL = 'customer-email';
    private const OPTION_SKU = 'sku';
    private const OPTION_PAYMENT_METHOD = 'payment-method';

    private const DEFAULT_PAYMENT_METHOD = 'checkmo';

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
            self::OPTION_CUSTOMER_EMAIL,
            'e',
            InputOption::VALUE_REQUIRED,
            'Email du client Magento a utiliser pour la facturation et la livraison'
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
            'Code du mode de paiement a utiliser',
            self::DEFAULT_PAYMENT_METHOD
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

        $count = (int) $input->getOption(self::OPTION_COUNT);
        $email = trim((string) $input->getOption(self::OPTION_CUSTOMER_EMAIL));
        $skus = array_values(array_unique(array_filter(array_map('trim', $input->getOption(self::OPTION_SKU)))));
        $paymentMethod = trim((string) $input->getOption(self::OPTION_PAYMENT_METHOD)) ?: self::DEFAULT_PAYMENT_METHOD;

        if ($count < 1) {
            $output->writeln('<error>L\'option --count doit etre un entier superieur a 0.</error>');
            return Command::FAILURE;
        }

        if ($email === '') {
            $output->writeln('<error>L\'option --customer-email est obligatoire.</error>');
            return Command::FAILURE;
        }

        if (empty($skus)) {
            $output->writeln('<error>Au moins un --sku est obligatoire.</error>');
            return Command::FAILURE;
        }

        try {
            $customer = $this->orderGenerator->getCustomerByEmail($email);
        } catch (Throwable $e) {
            $output->writeln(sprintf(
                '<error>Client introuvable pour l\'email "%s" : %s</error>',
                $email,
                $e->getMessage()
            ));
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
            '<info>Generation de %d commande(s) pour "%s" (paiement : %s, transporteur : transporter_transporter, %d SKU par commande)</info>',
            $count,
            $email,
            $paymentMethod,
            count($products)
        ));

        $progressBar = new ProgressBar($output, $count);
        $progressBar->start();

        $created = [];
        $errors = [];

        for ($i = 1; $i <= $count; $i++) {
            try {
                $order = $this->orderGenerator->createOrder($customer, $products, $paymentMethod);
                $created[] = $order->getIncrementId();
            } catch (Throwable $e) {
                $errors[] = sprintf('Commande #%d : %s', $i, $e->getMessage());
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
}
