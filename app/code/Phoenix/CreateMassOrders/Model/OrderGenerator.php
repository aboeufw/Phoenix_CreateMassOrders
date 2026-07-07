<?php

declare(strict_types=1);

namespace Phoenix\CreateMassOrders\Model;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
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

    /**
     * Decalage (en jours) applique a la date du jour pour estimer la date de livraison.
     */
    private const ESTIMATED_DELIVERY_OFFSET_DAYS = 2;

    /**
     * Attribut client stockant le numero client ERP.
     */
    private const CUSTOMER_ERP_ID_ATTRIBUTE = 'wesco_customer_erp_id';

    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly AddressRepositoryInterface $addressRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly QuoteFactory $quoteFactory,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly CartManagementInterface $cartManagement,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly PaymentConfig $paymentConfig,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function getCustomerByErpId(string $erpId): CustomerInterface
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(self::CUSTOMER_ERP_ID_ATTRIBUTE, $erpId)
            ->create();

        $result = $this->customerRepository->getList($searchCriteria);

        if ($result->getTotalCount() === 0) {
            throw new NoSuchEntityException(__(
                'Aucun client trouve avec le numero client ERP "%1" (attribut %2).',
                $erpId,
                self::CUSTOMER_ERP_ID_ATTRIBUTE
            ));
        }

        if ($result->getTotalCount() > 1) {
            throw new LocalizedException(__(
                'Plusieurs clients partagent le numero client ERP "%1", impossible de determiner lequel utiliser.',
                $erpId
            ));
        }

        return current($result->getItems());
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
     * @param array<int, array{sku: string, qty: float}> $skuQuantities
     * @return array{0: array<int, array{product: ProductInterface, qty: float}>, 1: string[]}
     *     Lignes produit/quantite trouvees et SKU introuvables.
     */
    public function loadProductsWithQuantities(array $skuQuantities): array
    {
        $items = [];
        $missing = [];

        foreach ($skuQuantities as $entry) {
            try {
                $product = $this->productRepository->get($entry['sku'], false, null, true);
                $items[] = ['product' => $product, 'qty' => $entry['qty']];
            } catch (NoSuchEntityException) {
                $missing[] = $entry['sku'];
            }
        }

        return [$items, $missing];
    }

    /**
     * Cree une commande pour le client donne, avec la quantite indiquee de chaque produit fourni.
     *
     * @param array<int, array{product: ProductInterface, qty: float}> $items
     * @throws LocalizedException
     */
    public function createOrder(CustomerInterface $customer, array $items, string $paymentMethod): OrderInterface
    {
        $store = $this->resolveStore($customer);

        $quote = $this->quoteFactory->create();
        $quote->setStore($store);
        $quote->setCurrency();
        $quote->assignCustomer($customer);
        $quote->setCustomerIsGuest(false);

        foreach ($items as $item) {
            $result = $quote->addProduct($item['product'], $item['qty']);
            if (is_string($result)) {
                throw new LocalizedException(__(
                    'Impossible d\'ajouter le SKU "%1" (quantite %2) a la commande : %3',
                    $item['product']->getSku(),
                    $item['qty'],
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

        $quote->setInventoryProcessed(false);
        $quote->collectTotals();
        $quote->reserveOrderId();

        // Le quote doit deja avoir un ID avant l'import du mode de paiement : certains
        // observers (ex: Magento_CustomerBalance\Observer\PaymentDataImportObserver)
        // rechargent le quote via payment->getQuote() et echouent sur un quote non persiste.
        $this->quoteRepository->save($quote);

        $quote->setPaymentMethod($paymentMethod);
        $quote->getPayment()->importData(['method' => $paymentMethod]);
        $this->quoteRepository->save($quote);

        try {
            $orderId = $this->cartManagement->placeOrder($quote->getId());
        } catch (Throwable $e) {
            throw new LocalizedException(__('Echec lors de la validation de la commande : %1', $e->getMessage()), $e);
        }

        $order = $this->orderRepository->get($orderId);

        $estimatedDeliveryDate = $this->estimatedDeliveryDate();
        $order->setData('wesco_first_estimated_delivre_date_from', $estimatedDeliveryDate);
        $order->setData('wesco_first_estimated_delivre_date_to', $estimatedDeliveryDate);

        return $this->orderRepository->save($order);
    }

    private function estimatedDeliveryDate(): string
    {
        return (new \DateTime('today'))
            ->modify(sprintf('+%d days', self::ESTIMATED_DELIVERY_OFFSET_DAYS))
            ->format('Y-m-d 00:00:00');
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
