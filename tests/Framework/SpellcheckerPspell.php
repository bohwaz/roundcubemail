<?php

use PHPUnit\Framework\TestCase;

/**
 * Test class to test rcube_spellcheck_pspell class
 */
class Framework_SpellcheckerPspell extends TestCase
{
    /**
     * Class constructor
     */
    public function test_class()
    {
        $object = new rcube_spellchecker_pspell(null, 'en');

        $this->assertInstanceOf('rcube_spellchecker_pspell', $object, 'Class constructor');
        $this->assertInstanceOf('rcube_spellchecker_engine', $object, 'Class constructor');
    }

    /**
     * Test languages() method
     */
    public function test_languages()
    {
        if (!extension_loaded('pspell')) {
            $this->markTestSkipped();
        }

        rcube::get_instance()->config->set('spellcheck_engine', 'pspell');

        $object = new rcube_spellchecker();

        $langs = $object->languages();

        $this->assertSame('English (US)', $langs['en']);
    }

    /**
     * Test check() method
     */
    public function test_check()
    {
        if (!extension_loaded('pspell')) {
            $this->markTestSkipped();
        }

        rcube::get_instance()->config->set('spellcheck_engine', 'pspell');

        $object = new rcube_spellchecker();

        $this->assertTrue($object->check('one'));

        // Test other methods that depend on the spellcheck result
        $this->assertSame(0, $object->found());
        $this->assertSame([], $object->get_words());

        $this->assertSame(
            '<?xml version="1.0" encoding="UTF-8"?><spellresult charschecked="3"></spellresult>',
            $object->get_xml()
        );

        $this->assertFalse($object->check('ony'));

        // Test other methods that depend on the spellcheck result
        $this->assertSame(1, $object->found());
        $this->assertSame(['ony'], $object->get_words());

        $this->assertMatchesRegularExpression(
            '|^<\?xml version="1.0" encoding="UTF-8"\?><spellresult charschecked="3"><c o="0" l="3">([a-zA-Z\t]+)</c></spellresult>$|',
            $object->get_xml()
        );

        // Test that links are ignored (#8527)
        $html = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8" /></head><body>'
            . '<p><a href="http://www.redacted.com">www.redacted.com</a></div></body></html>';

        $this->assertTrue($object->check($html, true));

        $html = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8" /></head><body>'
            . '<p><a href="http://www.redacted.com">http://www.redacted.com</a></div></body></html>';

        $this->assertTrue($object->check($html, true));

        $this->assertTrue($object->check('one http://www.redacted.com'));
        $this->assertTrue($object->check('one www.redacted.com'));
    }

    /**
     * Test get_suggestions() method
     */
    public function test_get_suggestions()
    {
        if (!extension_loaded('pspell')) {
            $this->markTestSkipped();
        }

        rcube::get_instance()->config->set('spellcheck_engine', 'pspell');

        $object = new rcube_spellchecker();

        $expected = ['ON', 'on', 'Ont', 'only', 'onya', 'NY', 'onyx', 'Ono', 'any', 'one'];
        $result   = $object->get_suggestions('ony');

        sort($expected);
        sort($result);

        $this->assertSame($expected, $result);
    }

    /**
     * Test get_words() method
     */
    public function test_get_words()
    {
        if (!extension_loaded('pspell')) {
            $this->markTestSkipped();
        }

        rcube::get_instance()->config->set('spellcheck_engine', 'pspell');

        $object = new rcube_spellchecker();

        $this->assertSame(['ony'], $object->get_words('ony'));
    }
}
