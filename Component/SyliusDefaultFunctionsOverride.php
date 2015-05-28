<?php

/**
 * Copy required/overwrite functions from Sylius bundle to make it possible to order an eZ object
 * Some helpfull functions you can find in the demo data bundle Sylius\Bundle\FixturesBundle\DataFixtures\ORM\Load[SOMEOBJECTS]Data
 * 
 * @author kristina
 *
 */

namespace xrow\syliusBundle\Component;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

use Sylius\Component\Cart\Event\CartItemEvent;
use Sylius\Component\Cart\Resolver\ItemResolvingException;
use Sylius\Component\Cart\SyliusCartEvents;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Shipping\Model\ShippingMethodInterface;
use Sylius\Component\Addressing\Model\AddressInterface;

use Sylius\Component\Core\SyliusCheckoutEvents;
use Sylius\Component\Core\SyliusOrderEvents;
use Sylius\Component\Order\OrderTransitions;

class SyliusDefaultFunctionsOverride
{
    private $container;
    private $entityManager;
    private $eventDispatcher;
    private $sylius = array();

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->entityManager = $this->container->get('doctrine.orm.entity_manager');
        $this->eventDispatcher = $this->container->get('event_dispatcher');
        $this->sylius['CartProvider'] = $this->container->get('sylius.cart_provider');
        $this->sylius['CartItemController'] = $this->container->get('sylius.controller.cart_item');
        $this->sylius['OrderRepository'] = $this->container->get('sylius.repository.order');
        $this->sylius['ProductRepository'] = $this->container->get('sylius.repository.product');
        $this->sylius['PriceCalculator'] = $this->container->get('sylius.price_calculator');
    }

    /**
     * @param  integer $contentId The contentId/object id of an ez object
     * @throws \Sylius\Component\Cart\Resolver\ItemResolvingException
     * @return \Sylius\Component\Core\Model\Order
     */
    public function addProductToCart($contentId)
    {
        // ONLY FOR TESTING Get order
        if($tmpOder = $this->sylius['OrderRepository']->find(1))
        {
            return $tmpOder;
        }

        $cart = $this->sylius['CartProvider']->getCart();
        $cartItem = $this->sylius['CartItemController']->createNew(); // Sylius\Component\Core\Model\OrderItem
        try {
            if (!$syliusProduct = $this->sylius['ProductRepository']->findOneBy(array('content_id' => $contentId))) {
                // Create new sylius product
                $syliusProduct = $this->createNewProductAndVariant($contentId);
            }
            $syliusProductVariant = $syliusProduct->getMasterVariant();
            // Put product variant to the cart
            $cartItem->setVariant($syliusProductVariant);

            $quantity = $cartItem->getQuantity();
            $context = array('quantity' => $quantity);
            /*
              we don't have here a user
            if (null !== $user = $cart->getUser()) {
                $context['groups'] = $user->getGroups()->toArray();
            }*/

            $cartItem->setUnitPrice($this->sylius['PriceCalculator']->calculate($syliusProductVariant, $context));
            foreach ($cart->getItems() as $cartItemTmp) {
                if ($cartItemTmp->equals($cartItem)) {
                    $quantity += $cartItemTmp->getQuantity();
                    break;
                }
            }

            $event = new CartItemEvent($cart, $cartItem);
            $event->isFresh(true);
            // Update models
            $this->eventDispatcher->dispatch(SyliusCartEvents::ITEM_ADD_INITIALIZE, $event);
            $this->eventDispatcher->dispatch(SyliusCartEvents::CART_CHANGE, new GenericEvent($cart));
            $this->eventDispatcher->dispatch(SyliusCartEvents::CART_SAVE_INITIALIZE, $event);

            return $cart;
        } catch (ItemResolvingException $exception) {
            throw new ItemResolvingException($exception->getMessage());
        }
        return null;
    }

    /**
     * get current logged in user and add his data to order
     * 
     * @param OrderInterface $order
     * @param array $userData
     * @return OrderInterface $order
     */
    public function checkoutOrder(OrderInterface $order, $userData)
    {
        // set temporary shipment
        $shipment = $this->createShipment($order);
        $order->addShipment($shipment);

        // set temporary billing address
        $billindAddress = $this->createAddress($userData);
        $order->setBillingAddress($billindAddress);

        $this->eventDispatcher->dispatch(SyliusCartEvents::CART_CHANGE, new GenericEvent($order));
        $this->eventDispatcher->dispatch(SyliusCheckoutEvents::SHIPPING_PRE_COMPLETE, new GenericEvent($order));
        $this->container->get('sm.factory')->get($order, OrderTransitions::GRAPH)->apply(OrderTransitions::SYLIUS_CREATE);

        // Calculate amount of the order
        $order->calculateTotal();
        // Set current time
        $order->complete();

        // set temporary user
        $user = $this->createUser($userData['first_name'], $userData['last_name'], $userData['email'], $billindAddress);
        $order->setUser($user);

        $payment = $this->createPayment($order);
        $order->addPayment($payment);
        $this->eventDispatcher->dispatch(SyliusCheckoutEvents::FINALIZE_PRE_COMPLETE, new GenericEvent($order));

        $this->eventDispatcher->dispatch(SyliusOrderEvents::PRE_CREATE, new GenericEvent($order));
        $this->eventDispatcher->dispatch(SyliusCheckoutEvents::FINALIZE_PRE_COMPLETE, new GenericEvent($order));
        $this->container->get('sm.factory')->get($order, OrderTransitions::GRAPH)->apply(OrderTransitions::SYLIUS_CREATE, true);

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        $this->eventDispatcher->dispatch(SyliusCheckoutEvents::FINALIZE_COMPLETE, new GenericEvent($order));
        $this->eventDispatcher->dispatch(SyliusOrderEvents::POST_CREATE, new GenericEvent($order));

        return $order;
    }

    /**
     * Create new Sylius product to make possible to order via Sylius
     * 
     * @param unknown $contentId
     * @return ProductInterface $product
     */
    private function createNewProductAndVariant($contentId)
    {
        $product = $this->sylius['ProductRepository']->createNew();
        $product->setContentId($contentId);

        $taxCategoryRepository = $this->entityManager->getRepository('\Sylius\Component\Taxation\Model\TaxCategory');
        $taxCategory = $taxCategoryRepository->find(1);
        $product->setTaxCategory($taxCategory);

        // get eZ Object
        $eZObject = $this->getEZObject($contentId, true);

        $name = $eZObject['contentObject']->getFieldValue('name')->__toString();
        $search_array = array('/û/', '/ù/', '/ú/', '/ø/', '/ô/', '/ò/', '/ó/', '/î/', '/ì/', '/í/', '/æ/', '/ê/', '/è/', '/é/', '/å/', '/â/', '/à/', '/á/', '/Û/', '/Ù/', '/Ú/', '/Ø/', '/Ô/', '/Ò/', '/Ó/', '/Î/', '/Ì/', '/Ì/', '/Í/', '/Æ/', '/Ê/', '/È/', '/É/', '/Å/', '/Â/', '/Â/', '/À/', '/Á/','/Ö/', '/Ä/', '/Ü/', "/'/", '/\&/', '/ö/', '/ä/', "/ /", '/ü/', '/ß/', '/\!/', '/\"/', '/\§/', '/\$/', '/\%/', '/\//', '/\(/', '/\)/', '/\=/', '/\?/', '/\@/', '/\#/', '/\*/', '/€/');
        $replace_array = array('u', 'u', 'u', 'o', 'o', 'o', 'o', 'i', 'i', 'i', 'ae', 'e', 'e', 'e', 'a', 'a', 'a', 'a', 'U', 'U', 'U', 'O', 'O', 'O', 'O', 'I', 'I', 'I', 'I', 'Ae', 'E', 'E', 'E', 'A', 'A', 'A', 'A', 'A', 'Oe', 'Ae', 'Ue', '', '+', 'oe', 'ae', "-", 'ue', 'ss', '', '', '', '', '', '', '', '', '', '', '', '', '', '');
        $slug = preg_replace($search_array, $replace_array, strtolower($name));
        $description = $eZObject['parentContentObject']->getFieldValue('description')->__toString();
        $locale = $this->container->getParameter('sylius.locale');

        $product->setContentId($contentId);
        $product->setCurrentLocale($locale);
        $product->setFallbackLocale($locale);
        $product->setSlug($slug);
        $product->setName($name);
        $product->setDescription($description);

        $product = $this->addMasterVariant($product, $eZObject['contentObject']);

        // get ArchType 
        switch($name) {
            case strpos($name, 'Kontakter') !== false:
                $archcode = 'kontakterepaper';
                break;
            case strpos($name, 'LEAD digital') !== false:
                $archcode = 'leaddigitalepaper';
                break;
            default:
                $archcode = 'wuvepaper';
                break;
        }
        $archetypeRepository = $this->container->get('sylius.repository.product_archetype');
        $archetype = $archetypeRepository->findOneBy(array('code' => $archcode));
        $product->setArchetype($archetype);

        $this->entityManager->persist($product);
        $this->entityManager->flush();
        return $product;
    }

    /**
     * Get eZ object, in our case this is the original product
     * 
     * @param unknown $contentId
     * @param string $getParent
     * @return array $eZObject
     */
    public function getEZObject($contentId, $getParent = false)
    {
        $eZAPIRepository = $this->container->get('ezpublish.api.repository');
        $contentCervice = $eZAPIRepository->getContentService();
        $eZObject = array('contentObject' => $contentCervice->loadContent($contentId));
        if($getParent) {
            $reverseRelations = $contentCervice->loadReverseRelations($eZObject['contentObject']->versionInfo->contentInfo);
            $parentContentInfoObject = $reverseRelations[0]->sourceContentInfo;
            $eZObject['parentContentObject'] = $contentCervice->loadContent($parentContentInfoObject->id);
        }
        return $eZObject;
    }

    /**
     * Adds master variant to product.
     * 
     * @param ProductInterface $product
     * @param unknown $contentObject
     * @return ProductInterface
     */
    protected function addMasterVariant(ProductInterface $product, $contentObject)
    {
        $variant = $product->getMasterVariant();
        $variant->setProduct($product);

        $price = (int)$contentObject->getFieldValue('price_de')->__toString() * 100;
        // Sylius Produkt Variant
        $variant->setSku($product->getContentId());
        $variant->setAvailableOn($contentObject->versionInfo->creationDate);
        $variant->setOnHand(100);
        $variant->setPrice($price);

        $product->setMasterVariant($variant);

        return $product;
    }

    /**
     * Create Sylius shipment
     * 
     * @param OrderInterface $order
     * @return ShipmentInterface $shipment
     */
    protected function createShipment(OrderInterface $order)
    {
        $shipment = $this->container->get('sylius.repository.shipment')->createNew();
        $shippingMethod = $this->container->get('sylius.repository.shipping_method')->find(1);
        $shipment->setMethod($shippingMethod);
        $shipment->setState(ShipmentInterface::STATE_CHECKOUT);

        foreach ($order->getInventoryUnits() as $item) {
            $shipment->addItem($item);
        }
        $this->entityManager->persist($shipment);

        return $shipment;
    }

    /**
     * Create Sylius address
     * 
     * @param array $user
     * @param string $type
     * @return AddressInterface $address
     */
    protected function createAddress($user)
    {
        $address = $this->container->get('sylius.repository.address')->createNew();
        $address->setFirstname($user['first_name']);
        $address->setLastname($user['last_name']);
        $address->setCity($user['billing_city']);
        $address->setStreet($user['billing_street']);
        $address->setPostcode($user['billing_postal_code']);

        $countryRepository = $this->entityManager->getRepository('\Sylius\Component\Addressing\Model\Country');
        $country = $this->container->get('sylius.repository.country')->find(87);
        $address->setCountry($country);

        $this->entityManager->persist($address);

        return $address;
    }

    /**
     * Create Sylius payment
     * 
     * @param OrderInterface $order
     * return PaymentInterface $payment
     */
    protected function createPayment(OrderInterface $order)
    {
        $payment = $this->container->get('sylius.repository.payment')->createNew();
        $payment->setOrder($order);
        $payment->setMethod($this->container->get('sylius.repository.payment_method')->find(1));
        $payment->setAmount($order->getTotal());
        $payment->setCurrency($order->getCurrency());
        $payment->setState(PaymentInterface::STATE_COMPLETED);

        $this->entityManager->persist($payment);

        return $payment;
    }

    /**
     * Create Sylius user
     * 
     * @param string $firstName
     * @param string $lastName
     * @param string $email
     * @param AddressInterface $billindAddress
     * @return UserInterface $user
     */
    protected function createUser($firstName, $lastName, $email, $billindAddress)
    {
        $user = $this->container->get('sylius.repository.user')->createNew();
        $user->setFirstname($firstName);
        $user->setLastname($lastName);
        $user->setUsername($email);
        $user->setEmail($email);
        $user->setPlainPassword('%6Gfr420?');
        $user->setRoles(array('ROLE_USER'));
        $user->setCurrency('EUR');
        $user->setEnabled(true);
        $user->setBillingAddress($billindAddress);

        $this->entityManager->persist($user);

        return $user;
    }
}