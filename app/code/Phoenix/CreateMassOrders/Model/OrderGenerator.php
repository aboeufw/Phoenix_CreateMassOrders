<?php

declare(strict_types=1);

namespace Phoenix\CreateMassOrders\Model;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Payment\Model\Config as PaymentConfig;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Throwable;

/**
 * Genere des commandes Magento a partir d'un client, d'une liste de SKU et d'un mode de paiement.
 * Utilise pour alimenter l'ERP en donnees de test sur un environnement de recette.
 */
class OrderGenerator
{
    /**
     * Transporteur "Standard" impose pour toutes les commandes generees.
     */
    private const SHIPPING_METHOD = 'transporter_transporter';

    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly AddressRepositoryInterface $addressRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly QuoteFactory $quoteFactory,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly CartManagementInterface $cartManagement,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly PaymentConfig $paymentConfig
    ) {
    }

    /**
     * @throws NoSuchEntityException
     */
    public function getCustomerByEmail(string $email): CustomerInterface
    {
        return $this->customerRepository->get($email);
    }

    /**
     * @throws LocalizedException
     */
    public function assertPaymentMethodIsActive(string $paymentMethod): void
    {
        $activeMethods = array_keys($this->paymentConfig->getActiveMethods());
        if (!in_array($paymentMethod, $activeMethods, true)) {
            throw new LocalizedException(__(
                'Le mode de paiement "%1" n\'est pas actif sur cette boutique. Modes actifs : %2',
                $paymentMethod,
                $activeMethods ? implode(', ', $activeMethods) : '(aucun)'
            ));
        }
    }

    /**
     * @param string[] $skus
     * @return array{0: ProductInterface[], 1: string[]} Liste des produits trouves et des SKU introuvables.
     */
    public function loadProductsBySku(array $skus): array
    {
        $products = [];
        $missing = [];

        foreach ($skus as $sku) {
            try {
                $products[] = $this->productRepository->get($sku, false, null, true);
            } catch (NoSuchEntityException) {
                $missing[] = $sku;
            }
        }

        return [$products, $missing];
    }

    /**
     * Cree une commande pour le client donne, avec un exemplaire de chaque produit fourni.
     *
     * @param ProductInterface[] $products
     * @throws LocalizedException
     */
    public function createOrder(CustomerInterface $customer, array $products, string $paymentMethod): OrderInterface
    {
        $store = $this->resolveStore($customer);

        $quote = $this->quoteFactory->create();
        $quote->setStore($store);
        $quote->setCurrency();
        $quote->assignCustomer($customer);
        $quote->setCustomerIsGuest(false);

        foreach ($products as $product) {
            $result = $quote->addProduct($product, 1);
            if (is_string($result)) {
                throw new LocalizedException(__(
                    'Impossible d\'ajouter le SKU "%1" a la commande : %2',
                    $product->getSku(),
                    $result
                ));
            }
        }

        $quote->getBillingAddress()->addData($this->buildAddressData($customer, 'billing'));

        $shippingAddress = $quote->getShippingAddress();
        $shippingAddress->addData($this->buildAddressData($customer, 'shipping'));
        $shippingAddress->setCollectShippingRates(true);
        $shippingAddress->collectShippingRates();
        $shippingAddress->setShippingMethod(self::SHIPPING_METHOD);

        $quote->setPaymentMethod($paymentMethod);
        $quote->setInventoryProcessed(false);
        $quote->getPayment()->importData(['method' => $paymentMethod]);

        $quote->collectTotals();
        $quote->reserveOrderId();
        $this->quoteRepository->save($quote);

        try {
            $orderId = $this->cartManagement->placeOrder($quote->getId());
        } catch (Throwable $e) {
            throw new LocalizedException(__('Echec lors de la validation de la commande : %1', $e->getMessage()), $e);
        }

        return $this->orderRepository->get($orderId);
    }

    private function resolveStore(CustomerInterface $customer): \Magento\Store\Api\Data\StoreInterface
    {
        $storeId = (int) $customer->getStoreId();

        if ($storeId) {
            try {
                return $this->storeManager->getStore($storeId);
            } catch (NoSuchEntityException) {
                // Store du client invalide/desactive, on se rabat sur le website.
            }
        }

        $websiteId = (int) $customer->getWebsiteId();

        try {
            $website = $this->storeManager->getWebsite($websiteId);
            $store = $website->getDefaultStore();
        } catch (NoSuchEntityException) {
            $store = null;
        }

        return $store ?? $this->storeManager->getDefaultStoreView() ?? $this->storeManager->getStore();
    }

    /**
     * @throws LocalizedException
     */
    private function buildAddressData(CustomerInterface $customer, string $type): array
    {
        $addressId = $type === 'billing' ? $customer->getDefaultBilling() : $customer->getDefaultShipping();

        if (!$addressId) {
            throw new LocalizedException(__(
                'Le client "%1" n\'a pas d\'adresse de %2 par defaut. Veuillez en definir une avant de generer des commandes.',
                $customer->getEmail(),
                $type === 'billing' ? 'facturation' : 'livraison'
            ));
        }

        try {
            $address = $this->addressRepository->getById((int) $addressId);
        } catch (NoSuchEntityException $e) {
            throw new LocalizedException(__(
                'Adresse de %1 introuvable pour le client "%2".',
                $type === 'billing' ? 'facturation' : 'livraison',
                $customer->getEmail()
            ), $e);
        }

        $region = $address->getRegion();

        return [
            'firstname' => $address->getFirstname() ?: $customer->getFirstname(),
            'lastname' => $address->getLastname() ?: $customer->getLastname(),
            'company' => $address->getCompany(),
            'street' => $address->getStreet(),
            'city' => $address->getCity(),
            'region' => $region ? $region->getRegion() : null,
            'region_id' => $region ? $region->getRegionId() : null,
            'postcode' => $address->getPostcode(),
            'country_id' => $address->getCountryId(),
            'telephone' => $address->getTelephone() ?: '0000000000',
            'email' => $customer->getEmail(),
            'save_in_address_book' => 0,
        ];
    }
}
