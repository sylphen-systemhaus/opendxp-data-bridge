<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Sylphen\DataBridgeBundle\test;

use OpenDxp\Model\DataObject\ClassDefinition\Data;
use PHPUnit\Framework\TestCase;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;

class OpenDxpFieldNameSanitizeTest extends TestCase
{
    public function testSanitizeKnownSyntheticNames(): void
    {
        $this->assertSame('seoBundle_title', Importer::sanitizeOpenDxpFieldName('seoBundle.title'));
        $this->assertSame('DD_WorkflowTransition', Importer::sanitizeOpenDxpFieldName('DD.WorkflowTransition'));
        $this->assertSame(
            '__virtual_DEPENDENT_DATAPORT_ID',
            Importer::sanitizeOpenDxpFieldName('__virtual_DEPENDENT DATAPORT ID')
        );
        $this->assertSame(
            '__virtual_FOLLOW_UP_DATAPORT_IDS_NAMES',
            Importer::sanitizeOpenDxpFieldName('__virtual_FOLLOW-UP DATAPORT IDS / NAMES')
        );
    }

    public function testSanitizeLeavesValidNamesUnchanged(): void
    {
        foreach (['id', '__init_action', '__complete_object', '__virtual_SKU', ''] as $name) {
            $this->assertSame($name, Importer::sanitizeOpenDxpFieldName($name));
            if ($name !== '') {
                $this->assertTrue(Importer::isValidOpenDxpFieldName($name));
            }
        }
    }

    public function testSanitizeTruncatesLongNamesToValidIdentifier(): void
    {
        $long = 'field_'.str_repeat('x', 80);
        $sanitized = Importer::sanitizeOpenDxpFieldName($long);
        $this->assertLessThanOrEqual(63, strlen($sanitized));
        $this->assertTrue(Importer::isValidOpenDxpFieldName($sanitized));
        $this->assertMatchesRegularExpression('/^[a-zA-Z_][a-zA-Z0-9_]*\z/', $sanitized);
    }

    public function testSanitizeCollisionsShareIdentifierButLogicalNamesStayDistinct(): void
    {
        $a = 'FOO BAR';
        $b = 'FOO-BAR';
        $this->assertSame(
            Importer::sanitizeOpenDxpFieldName($a),
            Importer::sanitizeOpenDxpFieldName($b)
        );

        $defA = $this->newDataDefinition();
        $defB = $this->newDataDefinition();
        Importer::setFieldDefinitionName($defA, $a);
        Importer::setFieldDefinitionName($defB, $b);

        $this->assertSame($defA->getName(), $defB->getName());
        $this->assertSame($a, Importer::getLogicalFieldName($defA));
        $this->assertSame($b, Importer::getLogicalFieldName($defB));
        $this->assertNotSame(
            Importer::getLogicalFieldName($defA),
            Importer::getLogicalFieldName($defB)
        );
    }

    public function testSetFieldDefinitionNameKeepsLogicalNameInWeakMap(): void
    {
        $cases = [
            'seoBundle.title' => 'seoBundle_title',
            'DD.WorkflowTransition' => 'DD_WorkflowTransition',
            '__virtual_DEPENDENT DATAPORT ID' => '__virtual_DEPENDENT_DATAPORT_ID',
            '__virtual_FOLLOW-UP DATAPORT IDS / NAMES' => '__virtual_FOLLOW_UP_DATAPORT_IDS_NAMES',
        ];

        foreach ($cases as $logical => $safe) {
            $def = $this->newDataDefinition();
            Importer::setFieldDefinitionName($def, $logical);
            $this->assertSame($safe, $def->getName(), $logical);
            $this->assertSame($logical, Importer::getLogicalFieldName($def), $logical);
            $this->assertTrue(Importer::isValidOpenDxpFieldName($def->getName()), $logical);
        }
    }

    public function testValidNamesAreNotStoredInWeakMap(): void
    {
        $def = $this->newDataDefinition();
        Importer::setFieldDefinitionName($def, '__complete_object');
        $this->assertSame('__complete_object', $def->getName());
        $this->assertSame('__complete_object', Importer::getLogicalFieldName($def));
    }

    public function testEmptyNameStaysEmpty(): void
    {
        $def = $this->newDataDefinition();
        Importer::setFieldDefinitionName($def, '');
        $this->assertSame('', $def->getName());
        $this->assertSame('', Importer::getLogicalFieldName($def));
    }

    /**
     * Minimal concrete Data stub — setName()/getName() come from OpenDXP Data.
     */
    private function newDataDefinition(): Data
    {
        return new class extends Data {
            public function getDataForEditmode(mixed $data, ?\OpenDxp\Model\DataObject\Concrete $object = null, array $params = []): mixed
            {
                return null;
            }

            public function getDataFromEditmode(mixed $data, ?\OpenDxp\Model\DataObject\Concrete $object = null, array $params = []): mixed
            {
                return null;
            }

            public function getFieldType(): string
            {
                return 'input';
            }
        };
    }
}
