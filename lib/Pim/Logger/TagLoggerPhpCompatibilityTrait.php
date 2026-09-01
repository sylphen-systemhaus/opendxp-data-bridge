<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Logger;

use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use DateTime;
use InvalidArgumentException;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\Service;
use OpenDxp\Model\Element\Tag;
use Psr\Log\LogLevel;

trait TagLoggerPhpCompatibilityTrait
{
    private static $clearedTags = [];
    private $minLogLevel = 2;
    private static $cache = [];
    private $tagsAssignmentBuffer = [];

    /**
     * @var int[]
     */
    private static $LEVELS = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    /**
     * @param int $minLogLevel
     */
    public function __construct($minLogLevel)
    {
        if (isset(self::$LEVELS[$minLogLevel])) {
            $minLogLevel = self::$LEVELS[$minLogLevel];
        }
        if (!in_array($minLogLevel, self::$LEVELS)) {
            throw new InvalidArgumentException('Log level does not exist');
        }

        $this->minLogLevel = $minLogLevel;
    }

    public function __destruct()
    {
        $this->tagsAssignmentBuffer = array_map(static function($tagData) {
            $tagData['dd_modificationDate'] = new DateTime();
            return $tagData;
        }, $this->tagsAssignmentBuffer);

        PimcoreDbRepository::getInstance()->createOrUpdate($this->tagsAssignmentBuffer, 'tags_assignment');
    }


    /**
     * @param string $level
     * @param string $message
     * @param array $context
     * @return void
     */
    public function doLog($level, $message, array $context = array())
    {
        if ($this->minLogLevel > (self::$LEVELS[$level] ?? 0) || strpos($message, 'Unexpected output') !== false) {
            return;
        }

        if (!empty($context['relatedObject']) && $context['relatedObject'] instanceof ElementInterface && $context['relatedObject']->getId() && !empty($context['dataportId'])) {
            $message = preg_replace('/Unable to write file at location: (([\w\s]+\/)+)\\w+\.\w*/', '$1', $message);
            $message = preg_replace('/'.preg_quote(OPENDXP_SYSTEM_TEMP_DIRECTORY, '/').'\/\w+\.\w*/', OPENDXP_SYSTEM_TEMP_DIRECTORY, $message);
            $message = preg_replace('/#\d{4,}/', '', $message);
            $tag = self::getOrCreateTag('Data Bridge/Dataport '.$context['dataportId'].'/'.str_replace('/', '-', $message));

            if (!isset(self::$clearedTags[$context['dataportId'].'-'.$context['relatedObject']->getId()])) {
                $tags = new Tag\Listing();
                $tags->addConditionParam('parentId = ?', $tag->getParentId());
                $tagIds = $tags->loadIdList();

                if ($tagIds) {
                    PimcoreDbRepository::getInstance()->execute('DELETE FROM tags_assignment WHERE ctype=? AND cid=? AND tagid IN (?)', [Service::getElementType($context['relatedObject']), $context['relatedObject']->getId(), $tagIds]);
                }

                self::$clearedTags[$context['dataportId'].'-'.$context['relatedObject']->getId()] = true;
            }

            $this->tagsAssignmentBuffer[] = [
                'tagid' => $tag->getId(),
                'ctype' => Service::getElementType($context['relatedObject']),
                'cid' => $context['relatedObject']->getId(),
            ];
        }
    }

    /**
     * @param string $tagPath
     * @return Tag
     */
    public static function getOrCreateTag($tagPath)
    {
        $parentTagId = 0;

        $tag = null;
        foreach (explode('/', $tagPath) as $tagItem) {
            $tagItem = mb_substr($tagItem, 0, 255); // name is VARCHAR(255) in database table
            $cacheKey = $tagItem;
            if (!empty($parentTagId)) {
                $cacheKey .= '~'.$parentTagId;
            }

            if(isset(self::$cache[$cacheKey])) {
                $tag = self::$cache[$cacheKey];
            } else {
                $tags = new Tag\Listing();
                $tags->addConditionParam('name = ?', $tagItem);
                $tags->setLimit(1);

                if (empty($parentTagId)) {
                    $tags->addConditionParam('parentId = 0 OR parentId IS NULL');
                } else {
                    $tags->addConditionParam('parentId = ?', $parentTagId);
                }

                $tags = $tags->load();
                if (count($tags) === 0) {
                    $tag = new Tag();
                    $tag->setName($tagItem);
                    $tag->setParentId($parentTagId);
                    $tag->correctPath();
                    PimcoreDbRepository::getInstance()->execute('INSERT IGNORE INTO tags (parentId, idPath, name) VALUES (?,?,?)', [$tag->getParentId(), $tag->getIdPath(), $tag->getName()]);

                    $tag->setId(PimcoreDbRepository::getInstance()->findOneInSql('SELECT id FROM tags WHERE idPath=? AND name=?', [$tag->getIdPath(), $tag->getName()]));
                } else {
                    $tag = $tags[0];
                }
            }

            $parentTagId = $tag->getId();
        }

        return $tag;
    }
}