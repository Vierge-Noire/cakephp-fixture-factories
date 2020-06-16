<?php
declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link          https://webrider.de/
 * @since         1.0.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace CakephpFixtureFactories\Test\TestCase\Factory;

use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use CakephpFixtureFactories\Error\AssociationBuilderException;
use CakephpFixtureFactories\Factory\AssociationBuilder;
use CakephpFixtureFactories\Test\Factory\AddressFactory;
use CakephpFixtureFactories\Test\Factory\ArticleFactory;
use CakephpFixtureFactories\Test\Factory\AuthorFactory;
use CakephpFixtureFactories\Test\Factory\BillFactory;
use PHPUnit\Framework\TestCase;
use TestApp\Model\Entity\Address;
use TestApp\Model\Entity\Country;
use TestPlugin\Model\Entity\Bill;
use TestPlugin\Model\Entity\Customer;

class AssociationBuilderTest extends TestCase
{
    /**
     * @var AssociationBuilder
     */
    public $associationBuilder;

    public function setUp()
    {
        $this->associationBuilder = new AssociationBuilder(AuthorFactory::make());
        Configure::write('TestFixtureNamespace', 'CakephpFixtureFactories\Test\Factory');

        parent::setUp();
    }

    public function tearDown()
    {
        Configure::delete('TestFixtureNamespace');
        parent::tearDown();
    }

    public function testCheckAssociationWithCorrectAssociation()
    {
        $this->assertTrue(
            $this->associationBuilder->checkAssociation('Address')
        );
        $this->assertTrue(
            $this->associationBuilder->checkAssociation('Address.City.Country')
        );
    }

    public function testCheckAssociationWithIncorrectAssociation()
    {
        $this->expectException(AssociationBuilderException::class);
        $this->associationBuilder->checkAssociation('Address.Country');
    }

    public function testGetFactoryFromTableName()
    {
        $street = 'Foo';
        $factory = $this->associationBuilder->getFactoryFromTableName('Address', compact('street'));
        $this->assertInstanceOf(AddressFactory::class, $factory);

        $address = $factory->persist();
        $this->assertSame($street, $address->street);

        $addresses = TableRegistry::getTableLocator()->get('Addresses')->find();
        $this->assertSame(1, $addresses->count());
    }

    public function testGetFactoryFromTableNameWrong()
    {
        $this->expectException(AssociationBuilderException::class);
        $this->associationBuilder->getFactoryFromTableName('Address.UnknownAssociation');
    }

    public function testGetAssociatedFactoryWithNoDepth()
    {
        $factory = $this->associationBuilder->getAssociatedFactory('Address');
        $this->assertInstanceOf(AddressFactory::class, $factory);
    }

    public function testGetAssociatedFactoryInPlugin()
    {
        $this->associationBuilder = new AssociationBuilder(ArticleFactory::make());
        $amount = 123;
        $factory = $this->associationBuilder->getAssociatedFactory('Bills', compact('amount'));
        $this->assertInstanceOf(BillFactory::class, $factory);

        $bill = $factory->persist();
        $this->assertEquals($amount, $bill->amount);

        $bills = TableRegistry::getTableLocator()->get('TestPlugin.Bills')->find();
        $this->assertSame(1, $bills->count());
    }

    public function testGetAssociatedFactoryWithOneDepth()
    {
        $street = 'Foo';
        $author = AuthorFactory::make()->with('BusinessAddress', [
            'street' => $street,
        ])->persist();

        $this->assertInstanceOf(Address::class, $author->business_address);
        $this->assertSame($street, $author->business_address->street);

        // There should now be two addresses in the DB
        $addresses = TableRegistry::getTableLocator()->get('Addresses')->find();
        $this->assertSame(2, $addresses->count());
    }

    public function testGetAssociatedFactoryWithMultipleDepth()
    {
        $country = 'Foo';
        $author = AuthorFactory::make()->with('BusinessAddress.City.Country', [
            'name' => $country,
        ])->persist();

        $this->assertInstanceOf(Country::class, $author->business_address->city->country);
        $this->assertSame($country, $author->business_address->city->country->name);

        // There should now be two addresses in the DB
        $addresses = TableRegistry::getTableLocator()->get('Addresses')->find();
        $this->assertSame(2, $addresses->count());
    }

    public function testGetAssociatedFactoryWithMultipleDepthInPlugin()
    {
        $this->associationBuilder = new AssociationBuilder(ArticleFactory::make());
        $name = 'Foo';
        $article = ArticleFactory::make()->with('Bills.Customer', compact('name'))->persist();

        $this->assertInstanceOf(Customer::class, $article->bills[0]->customer);
        $this->assertSame($name, $article->bills[0]->customer->name);

        $customers = TableRegistry::getTableLocator()->get('TestPlugin.Customers')->find();
        $this->assertSame(1, $customers->count());
    }

    public function testGetAssociatedFactoryInPluginWithNumber()
    {
        $this->associationBuilder = new AssociationBuilder(ArticleFactory::make());
        $n = 10;
        $article = ArticleFactory::make()->with('Bills', $n)->persist();

        $this->assertInstanceOf(Bill::class, $article->bills[0]);

        $bills = TableRegistry::getTableLocator()->get('TestPlugin.Bills')->find();
        $this->assertSame($n, $bills->count());
    }

    public function testGetAssociatedFactoryInPluginWithMultipleConstructs()
    {
        $this->associationBuilder = new AssociationBuilder(ArticleFactory::make());
        $n = 10;
        $article = ArticleFactory::make()->with('Bills', BillFactory::make($n)->with('Customer'))->persist();

        $this->assertInstanceOf(Bill::class, $article->bills[0]);
        $this->assertInstanceOf(Customer::class, $article->bills[0]->customer);

        $this->assertSame(
            $n,
            TableRegistry::getTableLocator()->get('TestPlugin.Bills')->find()->count()
        );

        $this->assertSame(
            $n,
            TableRegistry::getTableLocator()->get('TestPlugin.Customers')->find()->count()
        );
    }
}