<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\Command;

use Sylphen\DataBridgeBundle\Controller\ImportconfigController;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Parser\SortedFileIterator\SplFileInfoSortedFileIterator;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\Tools\Installer;
use OpenDxp\Console\AbstractCommand;
use OpenDxp\Model\User;
use SplFileInfo;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class DeploymentDataportRebuildCommand extends AbstractCommand
{
    /** @var ImportconfigController */
    private $importConfigController;

    public function __construct(ImportconfigController $importConfigController)
    {
        parent::__construct();
        $this->importConfigController = $importConfigController;
    }

    public static function getDefaultName(): string
    {
        return 'data-bridge:deployment:dataport-rebuild';
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Recreate / Update all dataports based on the JSON files in '.Installer::getConfigPath())
            ->addArgument('dataport', InputArgument::OPTIONAL, 'comma-separated list of dataport ids or names to be recreated')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force to overwrite existing dataport')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Source folder / URL with dataport definition JSON files. For URL, provide the main domain of the source Pimcore, e.g. https://example.org', Installer::getConfigPath())
            ->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'API key to access the source')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dataports = array_filter(explode(',', $input->getArgument('dataport') ?? ''));
        $force = $input->getOption('force');
        $source = $input->getOption('source');
        if($source === Installer::getConfigPath()) {
            $force = true;
        }

        $dataportRepository = Dataport::getInstance();

        if (filter_var($source, FILTER_VALIDATE_URL)) {
            $source = rtrim($source, '/');
            $apiKey = $input->getOption('api-key');
            if (empty($apiKey)) {
                $output->writeln('API key is required to access remote source. Please add --api-key option');
                return 1;
            }

            if (empty($dataports)) {
                $curlHandle = Helper::getCurlResource($source.'/api/dataports?apikey='.$apiKey);
                $responseList = curl_exec($curlHandle);
                if(curl_getinfo($curlHandle, CURLINFO_HTTP_CODE) !== Response::HTTP_OK) {
                    $output->writeln('Could not load data from '.$source.'/api/dataports?apikey='.$apiKey);
                    return 1;
                }

                $dataports = array_keys(json_decode($responseList, true));
            }

            $tmpDirectory = OPENDXP_SYSTEM_TEMP_DIRECTORY.'/data-bridge-sync-'.uniqid();
            if (!is_dir($tmpDirectory) && !mkdir($tmpDirectory, 0755, true) && !is_dir($tmpDirectory)) {
                throw new \RuntimeException(sprintf('Directory "%s" could not be created', $tmpDirectory));
            }
            register_shutdown_function(static function() use ($tmpDirectory) {
                recursiveDelete($tmpDirectory);
            });

            $output->writeln('Downloading dataport configurations ...');
            foreach ($dataports as &$dataportId) {
                $curlHandle = Helper::getCurlResource($source.'/api/dataports/'.urlencode($dataportId).'?apikey='.$apiKey);

                $dataportConfiguration = curl_exec($curlHandle);
                if (curl_getinfo($curlHandle, CURLINFO_HTTP_CODE) !== Response::HTTP_OK) {
                    $output->writeln('Could not load dataport definition for dataport '.$dataportId.' - skipping.');
                    continue;
                }

                $dataportConfiguration = json_decode($dataportConfiguration, true);
                if($dataportConfiguration[$dataportRepository->getTableName()]['id'] != $dataportId) {
                    $dataportId = $dataportConfiguration[$dataportRepository->getTableName()]['id'] + 9999;
                    unset($dataportConfiguration[$dataportRepository->getTableName()]['id']);
                }

                file_put_contents($tmpDirectory.'/dataport_'.$dataportId.'.json', json_encode($dataportConfiguration, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
            }
            unset($dataportId);

            $source = $tmpDirectory;
        }

        $directoryIterator = new \FilesystemIterator($source);
        $fileIterator = new SplFileInfoSortedFileIterator(new \CallbackFilterIterator(
            new \IteratorIterator($directoryIterator), static function(\SplFileInfo $fileInfo) use ($dataports, $dataportRepository) {
            if (!$fileInfo->isFile()) {
                return false;
            }

            if (preg_match('/dataport_(\d+).json$/', $fileInfo->getFilename(), $dataportId)) {
                if (count($dataports) === 0 || in_array($dataportId[1], $dataports)) {
                    return true;
                }
            }

            $dataportConfiguration = json_decode(file_get_contents($fileInfo->getRealPath()), true);
            return in_array($dataportConfiguration[$dataportRepository->getTableName()]['name'] ?? null, $dataports);
        }));

        $progressBar = new ProgressBar($output, $fileIterator->count());
        $progressBar->start();
        $systemUser = User::getById(0);
        /** @var SplFileInfo $fileInfo */
        foreach($fileIterator as $fileInfo) {
            $importDataportConfiguration = json_decode(file_get_contents($fileInfo->getRealPath()), true);

            $existingDataportId = $importDataportConfiguration[$dataportRepository->getTableName()]['id'] ?? null;
            if (!$existingDataportId) {
                $existingDataportId = $dataportRepository->get($importDataportConfiguration[$dataportRepository->getTableName()]['name'])['id'] ?? null;
            }

            if($existingDataportId && !$force) {
                $versions = $dataportRepository->getVersions($existingDataportId);
                $existingDataportConfiguration = null;
                if($versions) {
                    $existingDataportConfiguration = reset($versions)['config'];
                }

                if ($existingDataportConfiguration && ($existingDataportConfiguration['user']['id'] ?? $systemUser->getId()) != $systemUser->getId()) {
                    $helper = $this->getHelper('question');
                    $question = new ConfirmationQuestion(
                        'Latest version of dataport "'.$existingDataportConfiguration[$dataportRepository->getTableName()]['name'].'" (#'.$existingDataportConfiguration[$dataportRepository->getTableName()]['id'].') has not been imported but manually edited by '.$existingDataportConfiguration['user']['username'].'. Do you want to overwrite the dataport configuration anyway? (Default: n) [y,n] ',
                        false
                    );

                    if (!$helper->ask($input, $output, $question)) {
                        $progressBar->advance();
                        continue;
                    }
                }
            }

            $this->importConfigController->importDataport($importDataportConfiguration);
            $progressBar->advance();
        }

        $progressBar->finish();
        $output->writeln('');
        $output->writeln('Dataports successfully updated');

        return 0;
    }
}
