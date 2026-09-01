<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\TextGeneration;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Exception;
use InvalidArgumentException;
use JsonException;
use OpenDxp\Model\User;
use OpenDxp\Model\WebsiteSetting;
use OpenDxp\Tool;
use OpenDxp\Tool\Admin;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

class OpenAiTextGenerator implements TextGenerator
{
    use LoggerAwareTrait;

    public const ORIGIN = 'https://api.openai.com';
    public const API_VERSION = 'v1';
    public const OPEN_AI_URL = self::ORIGIN.'/'.self::API_VERSION;

    const MAX_TOKENS = 4096; // The token count of your prompt plus max_tokens cannot exceed the model's context length. Most models have a context length of 2048 tokens (except for the newest models, which support 4096)., see https://beta.openai.com/docs/api-reference/completions/create

    /** @var string */
    private $apiKey;

    public function __construct($apiKey = null)
    {
        $this->apiKey = $apiKey;
    }

    public function generate($text)
    {
        $text = trim(strip_tags($text));
        $strlen = mb_strlen($text);

        if($strlen / 2 > self::MAX_TOKENS) {
            $taskStart = mb_strpos($text, 'Task: ');
            if($taskStart !== false) {
                $text = mb_substr($text, 0, self::MAX_TOKENS - 100 - ($strlen - $taskStart)).' ... '.mb_substr($text, $taskStart);
                $strlen = mb_strlen($text);
            } else {
                throw new Exception('Input is too long, the maximum token count of the used model is '.self::MAX_TOKENS);
            }
        }

        if ($strlen === 0) {
            throw new InvalidArgumentException('No input provided');
        }

        $domain = parse_url(Helper::getHostUrl(), PHP_URL_HOST);
        if (empty($domain)) {
            $domain = Helper::getPimcoreSystemConfiguration('general')['domain'];
        }
        if (empty($domain)) {
            $domain = Tool::getHostname();
        }

        $sleep = 1;
        complete:
        //$engine = 'text-davinci-003';
        //$engine = 'gpt-3.5-turbo';
        //$engine = 'gpt-4o-mini';
        $engine = 'gpt-5-mini';
        //$url = self::OPEN_AI_URL.'/engines/'.$engine.'/completions';
        //$url = self::OPEN_AI_URL.'/chat/completions';
        $url = self::OPEN_AI_URL.'/responses';

        $curl = curl_init();

        $user = Helper::getUser();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode([
                "model" => $engine,
                "messages" => [
                    [
                        'role' => 'user',
                        'content' => $text
                    ]
                ],
                'user' => $user->getName().' '.$domain,
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer '.$this->apiKey,
            ],
        ]);
        $response = curl_exec($curl);
        curl_close($curl);

        $complete = json_decode($response, true, 512, (defined('JSON_THROW_ON_ERROR') ? JSON_THROW_ON_ERROR : 0));
        if (!defined('JSON_THROW_ON_ERROR') && json_last_error()) {
            throw new JsonException('Invalid JSON: '.$response);
        }

        if(!empty($complete['error'])) {
            if(preg_match('/(\d+) in your prompt; (\d+) for the completion/', $complete['error']['message'], $match)) {
                $newMaxTokens = $match[2] - $match[1];
                if($newMaxTokens < $maxTokens) {
                    $maxTokens = $newMaxTokens;
                    goto complete;
                }
            }

            if (preg_match('/(\d+) in the messages, (\d+) in the completion/', $complete['error']['message'], $match)) {
                $newMaxTokens = $match[2] - $match[1];
                if ($newMaxTokens < $maxTokens) {
                    $maxTokens = $newMaxTokens;
                    goto complete;
                }
            }

            if (preg_match('/Please try again in (\d+)s/', $complete['error']['message'], $match)) {
                $this->logger->info('OpenAI\'s rate limit for your account is '.round(60 / $match[1]).' requests per second. Waiting '.ceil($match[1]).' seconds to continue ... (consider upgrading to a different account type, see https://openai.com/blog/chatgpt-plus)');
                sleep(ceil($match[1]));
                goto complete;
            }

            if(strpos($complete['error']['message'], 'That model is currently overloaded with other requests.') !== false) {
                $this->logger->info('OpenAI is overloaded currently. Waiting 1 second to continue.');
                sleep(1);
                goto complete;
            }

            if (preg_match('/Limit: (\d+) \/ min/', $complete['error']['message'], $match)) {
                $this->logger->info('OpenAI\'s rate limit refused the request. Waiting '.$sleep.' seconds to continue ...');
                sleep($sleep);
                $sleep *= 2;
                goto complete;
            }

            throw new Exception($complete['error']['message']);
        }

        return preg_replace('/[\pZ\pC]/u', ' ', $complete['choices'][0]['message']['content'] ?? '');
    }
}