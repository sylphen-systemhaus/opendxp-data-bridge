<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Sylphen\DataBridgeBundle\test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;

class ItemMoldBuilderLegacyClassNameTest extends TestCase
{
    #[DataProvider('normalizeProvider')]
    public function testNormalizeLegacyModelClassName($input, $expected): void
    {
        self::assertSame($expected, ItemMoldBuilder::normalizeLegacyModelClassName($input));
    }

    public static function normalizeProvider(): array
    {
        return [
            'asset FQN' => ['Pimcore\\Model\\Asset', 'OpenDxp\\Model\\Asset'],
            'document page FQN' => ['Pimcore\\Model\\Document\\Page', 'OpenDxp\\Model\\Document\\Page'],
            'leading backslash' => ['\\Pimcore\\Model\\Asset', '\\OpenDxp\\Model\\Asset'],
            'already OpenDxp' => ['OpenDxp\\Model\\Asset', 'OpenDxp\\Model\\Asset'],
            'numeric class id' => [42, 42],
            'string class id' => ['ANL', 'ANL'],
            'null' => [null, null],
            'empty string' => ['', ''],
            'unrelated Pimcore ns' => ['Pimcore\\Bundle\\Foo', 'Pimcore\\Bundle\\Foo'],
        ];
    }
}
