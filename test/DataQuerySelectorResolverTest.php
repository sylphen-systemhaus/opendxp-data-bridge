<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\test;

use ArrayIterator;
use Sylphen\DataBridgeBundle\lib\Pim\Item\DataQuerySelectorResolver;
use DateTimeImmutable;
use DirectoryIterator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SplFileInfo;
use stdClass;

class DataQuerySelectorResolverTest extends TestCase
{
    /** @var DataQuerySelectorResolver */
    private $dataQuerySelectorResolver;

    protected function setUp(): void
    {
        $this->dataQuerySelectorResolver = new DataQuerySelectorResolver(__DIR__);
        foreach(new DirectoryIterator(__DIR__) as $file) {
            /** @var SplFileInfo $file */
            if($file->isDot()) {
                continue;
            }
            if($file->getFilename() !== basename(__FILE__)) {
                unlink($file->getPathname());
            }
        }
    }

    public function testResolve()
    {
        $object = new class() {
            public function getScalar()
            {
                return 'abc';
            }

            public function getDate()
            {
                return new DateTimeImmutable('2020-01-04 12:00:00');
            }

            public function getObject() {
                return new class() {
                    public function getScalar() {
                        return '123';
                    }

                    public function getDate()
                    {
                        return new DateTimeImmutable('2020-02-05 14:00:00');
                    }
                };
            }

            public function getArray() {
                return [
                    new class() {
                        public function getArrayItemValue() {
                           return 'foo';
                        }

                        public function getId() {
                           return 1;
                        }

                        public function getObject() {
                            return new class() {
                                public function getScalar()
                                {
                                    return 'x';
                                }

                                public function getAnotherScalar()
                                {
                                    return 'y';
                                }

                                public function getArray() {
                                    return [
                                        new class() {
                                            public function getId() {
                                                return 13;
                                            }
                                        }
                                    ];
                                }
                            };
                        }
                    },
                    new class() {
                        public function getArrayItemValue() {
                            return 'bar';
                        }

                        public function getId() {
                            return 2;
                        }

                        public function getObject() {
                            return new class() {
                                public function getScalar() {
                                    return 'y';
                                }

                                public function getAnotherScalar()
                                {
                                    return 'z';
                                }

                                public function getArray() {
                                    return [
                                        new class() {
                                            public function getId() {
                                                return 13;
                                            }
                                        },
                                        new class() {
                                            public function getId() {
                                                return 12;
                                            }
                                        }
                                    ];
                                }
                            };
                        }
                    }
                ];
            }

            public function getAssocArray()
            {
                return [
                    'de' => new class() {
                        public function getArrayItemValue()
                        {
                            return 'value-de';
                        }
                    },
                    'en' => new class() {
                        public function getArrayItemValue()
                        {
                            return 'value-en';
                        }
                    },
                ];
            }

            public function getArrayIterator()
            {
                return new ArrayIterator([
                    new class() {
                        public function getArrayItemValue()
                        {
                            return 'value-de';
                        }
                    },
                    new class() {
                        public function getArrayItemValue()
                        {
                            return 'value-en';
                        }
                    },
                ]);
            }
        };

        $this->assertEquals('abc', $this->dataQuerySelectorResolver->resolve('scalar', $object, false, new NullLogger()));
        $this->assertEquals('abc', $this->dataQuerySelectorResolver->resolve('getScalar', $object, false, new NullLogger()));
        $this->assertEquals('123', $this->dataQuerySelectorResolver->resolve('object:scalar', $object, false, new NullLogger()));
        $this->assertEquals('123,00', $this->dataQuerySelectorResolver->resolve('object:scalar:number_format#2,\,,', $object, false, new NullLogger()));
        $this->assertEquals('ABC', $this->dataQuerySelectorResolver->resolve('scalar:strtoupper', $object, false, new NullLogger()));
        $this->assertFalse($this->dataQuerySelectorResolver->resolve('scalar:empty', $object, false, new NullLogger()));
        $this->assertEquals('BC', $this->dataQuerySelectorResolver->resolve('scalar:strtoupper:substr#%s,1,2', $object, false, new NullLogger()));
        $this->assertEquals('ab', $this->dataQuerySelectorResolver->resolve('scalar:str_replace#c,,%s', $object, false, new NullLogger()));
        $this->assertEquals('04.01.20 12:00:00', $this->dataQuerySelectorResolver->resolve('date:"format#d.m.y H:i:s"', $object, false, new NullLogger()));
        $this->assertEquals('05.02.20 14:00:00', $this->dataQuerySelectorResolver->resolve('object:date:"format#d.m.y H:i:s"', $object, false, new NullLogger()));
        $this->assertEquals(['scalar' => 'abc', 'object:scalar' => '123'], $this->dataQuerySelectorResolver->resolve('(scalar;object:scalar)', $object, false, new NullLogger()));
        $this->assertEquals(['foo', 'bar'], $this->dataQuerySelectorResolver->resolve('array:all:(arrayItemValue)', $object, false, new NullLogger()));
        $this->assertEquals(['de' => 'value-de', 'en' => 'value-en'], $this->dataQuerySelectorResolver->resolve('assocArray:each:(arrayItemValue)', $object, false, new NullLogger()));
        $this->assertEquals(['value-de', 'value-en'], $this->dataQuerySelectorResolver->resolve('arrayIterator:each:(arrayItemValue)', $object, false, new NullLogger()));
        $this->assertEquals('value-de', $this->dataQuerySelectorResolver->resolve('arrayIterator:0:arrayItemValue', $object, false, new NullLogger()));
        $this->assertEquals([['test' => 'foo'], ['test' => 'bar']], $this->dataQuerySelectorResolver->resolve('array:all:(arrayItemValue as test)', $object, false, new NullLogger()));
        $this->assertEquals([['arrayItemValue' => 'foo', 'id' => 1], ['arrayItemValue' => 'bar', 'id' => 2]], $this->dataQuerySelectorResolver->resolve('array:all:(arrayItemValue;id)', $object, false, new NullLogger()));
        $this->assertEquals([['test' => 'foo', 'identifier' => 1], ['test' => 'bar', 'identifier' => 2]], $this->dataQuerySelectorResolver->resolve('array:all:(arrayItemValue as test;id as identifier)', $object, false, new NullLogger()));
        $this->assertEquals('abc 123', $this->dataQuerySelectorResolver->resolve('(scalar;object:scalar):implode# ,%s', $object, false, new NullLogger()));
        $this->assertEquals('foo bar', $this->dataQuerySelectorResolver->resolve('array:all:(arrayItemValue):implode# ,%s', $object, false, new NullLogger()));
        $this->assertEquals('foo bar', $this->dataQuerySelectorResolver->resolve('array:(arrayItemValue):implode# ,%s', $object, false, new NullLogger())); // optional, not documented
        $this->assertEquals('x y', $this->dataQuerySelectorResolver->resolve('array:all:(object:scalar):implode# ,%s', $object, false, new NullLogger()));
        $this->assertEquals([[13], [13,12]], $this->dataQuerySelectorResolver->resolve('array:all:(object:array:all(id))', $object, false, new NullLogger()));
        $this->assertEquals(['scalar' => 'abc', 'array:all' => [1,2]], $this->dataQuerySelectorResolver->resolve('(scalar;array:all:(id))', $object, false, new NullLogger()));
        $this->assertEquals([['id' => 1, 'subIds' => [13]], ['id' => 2, 'subIds' => [13,12]]], $this->dataQuerySelectorResolver->resolve('array:all:(id;object:array:all as subIds:(id))', $object, false, new NullLogger()));
        $this->assertEquals([['subIds' => [13], 'arrayItemValue' => 'foo'], ['subIds' => [13, 12], 'arrayItemValue' => 'bar']], $this->dataQuerySelectorResolver->resolve('array:all:(object:array:all as subIds:(id);arrayItemValue)', $object, false, new NullLogger()));
        $this->assertEquals([['data' => ['scalar' => 'x', 'anotherScalar' => 'y'], 'id' => 1], ['data' => ['scalar' => 'y', 'anotherScalar' => 'z'], 'id' => 2]], $this->dataQuerySelectorResolver->resolve('array:all:(object as data:(scalar;anotherScalar);id)', $object, false, new NullLogger()));
        $this->assertEquals(['array:all' => [1,2], 'scalar' => 'abc'], $this->dataQuerySelectorResolver->resolve('(array:all:(id);scalar)', $object, false, new NullLogger()));
        $this->assertEquals([['id' => 1, 'data' => ['scalar' => 'x', 'anotherScalar' => 'y']], ['id' => 2, 'data' => ['scalar' => 'y', 'anotherScalar' => 'z']]], $this->dataQuerySelectorResolver->resolve('array:all:(id;object as data:(scalar;anotherScalar))', $object, false, new NullLogger()));
        $this->assertEquals(['group1' => ['scalar' => 'abc', 'object:scalar' => '123']], $this->dataQuerySelectorResolver->resolve('self as group1:(scalar;object:scalar)', $object, false, new NullLogger()));
        $this->assertEquals(['group1' => ['scalar' => 'abc', 'object:scalar' => '123']], $this->dataQuerySelectorResolver->resolve('(scalar;object:scalar) as group1', $object, false, new NullLogger()));
        $this->assertEquals(['group1' => ['scalar' => 'abc', 'object:scalar' => '123'], 'scalar' => 'abc'], $this->dataQuerySelectorResolver->resolve('(scalar;object:scalar) as group1;scalar', $object, false, new NullLogger()));
        $this->assertEquals(['group1' => ['scalar' => 'abc', 'object:scalar' => '123'], 'group2' => [1,2]], $this->dataQuerySelectorResolver->resolve('(scalar;object:scalar) as group1;array:all as group2:(id)', $object, false, new NullLogger()));
        $this->assertEquals([['ids' => [13], 'arrayItemValue' => 'foo'], ['ids' => [13, 12], 'arrayItemValue' => 'bar']], $this->dataQuerySelectorResolver->resolve('array:each:(object:array:each:(id) as ids;arrayItemValue)', $object, false, new NullLogger()));
        $this->assertEquals([['arrayItemValue' => 'foo', 'ids' => [13]], ['arrayItemValue' => 'bar', 'ids' => [13, 12]]], $this->dataQuerySelectorResolver->resolve('array:each:(arrayItemValue;object:array:each:(id) as ids)', $object, false, new NullLogger()));
        $this->assertEquals([['arrayItemValue' => 'foo', 'ids' => [13]], ['arrayItemValue' => 'bar', 'ids' => [13, 12]]], $this->dataQuerySelectorResolver->resolve('array:each:(arrayItemValue;(object:array:each:(id)) as ids)', $object, false, new NullLogger()));
    }
}
