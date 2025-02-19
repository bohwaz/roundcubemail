<?php

use PHPUnit\Framework\TestCase;

class ExampleAddressbook_Plugin extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        include_once __DIR__ . '/../example_addressbook.php';
        include_once __DIR__ . '/../example_addressbook_backend.php';
    }

    /**
     * Plugin object construction test
     */
    public function test_constructor()
    {
        $rcube  = rcube::get_instance();
        $plugin = new example_addressbook($rcube->plugins);

        $this->assertInstanceOf('example_addressbook', $plugin);
        $this->assertInstanceOf('rcube_plugin', $plugin);

        $plugin->init();
    }

    /**
     * Test address_sources()
     */
    public function test_address_sources()
    {
        $rcube  = rcube::get_instance();
        $plugin = new example_addressbook($rcube->plugins);

        $result = $plugin->address_sources(['sources' => []]);

        $this->assertSame('static', $result['sources']['static']['id']);
    }
}
