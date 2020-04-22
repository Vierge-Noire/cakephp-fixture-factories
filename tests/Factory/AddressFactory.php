<?php

namespace TestFixtureFactories\Test\Factory;

use TestFixtureFactories\Factory\BaseFactory;

class AddressFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'addresses';
    }

    public function withCity($parameter)
    {
        return $this->with('city', CityFactory::make($parameter));
    }
}
