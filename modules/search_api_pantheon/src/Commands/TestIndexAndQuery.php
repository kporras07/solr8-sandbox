<?php

namespace Drupal\search_api_pantheon\Commands;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\search_api_pantheon\Services\Endpoint;
use Drupal\search_api_pantheon\Services\PantheonGuzzle;
use Drupal\search_api_pantheon\Services\SolariumClient;
use Drupal\search_api_solr\SolrConnectorInterface;
use Drush\Commands\DrushCommands;
use Solarium\Core\Query\Result\ResultInterface;
use Solarium\QueryType\Update\Query\Document as UpdateDocument;
use Solarium\QueryType\Update\Query\Query as UpdateQuery;
use Symfony\Component\Yaml\Yaml;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\search_api\Entity\Index;

/**
 * A Drush command file.
 *
 * In addition to this file, you need a drush.services.yml
 * in root of your module, and a composer.json file that provides the name
 * of the services file to use.
 *
 * See these files for an example of injecting Drupal services:
 *   - http://cgit.drupalcode.org/devel/tree/src/Commands/DevelCommands.php
 *   - http://cgit.drupalcode.org/devel/tree/drush.services.yml
 */
class TestIndexAndQuery extends DrushCommands {

  protected PantheonGuzzle $pantheonGuzzle;
  protected Endpoint $endpoint;
  protected SolariumClient $solr;

  /**
   * Class Constructor.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   Injected by container.
   * @param \Drupal\search_api_pantheon\Services\PantheonGuzzle $pantheonGuzzle
   *   Injected by container.
   * @param \Drupal\search_api_pantheon\Services\Endpoint $endpoint
   *   Injected by container.
   * @param \Drupal\search_api_pantheon\Services\SolariumClient $solariumClient
   *   Injected by container.
   */
  public function __construct(
        LoggerChannelFactoryInterface $loggerChannelFactory,
        PantheonGuzzle $pantheonGuzzle,
        Endpoint $endpoint,
        SolariumClient $solariumClient
    ) {
    $this->logger = $loggerChannelFactory->get('SearchAPIPantheon Drush');
    $this->pantheonGuzzle = $pantheonGuzzle;
    $this->endpoint = $endpoint;
    $this->solr = $solariumClient;
  }

  /**
   * Search_api_pantheon:test-index-and-query.
   *
   * @usage search-api-pantheon:test-index-and-query
   *   Connect to the solr8 server to index a single item and immediately query it.
   *
   * @command search-api-pantheon:test-index-and-query
   * @aliases sap-tiq
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   * @throws \JsonException
   * @throws \Exception
   */
  public function testIndexAndQuery() {
    $index = NULL;
    try {
      $drupal_root = \DRUPAL_ROOT;

      $response = $this->pingSolrHost();
      $this->logger()->notice('Ping Received Response? {var}', [
            'var' => $response instanceof ResultInterface ? '✅' : '❌',
        ]);
      $this->logger()->notice('Response http status == 200? {var}', [
            'var' => $response->getResponse()->getStatusCode() === 200 ? '✅' : '❌',
        ]);
      if ($response->getResponse()->getStatusCode() !== 200) {
        throw new \Exception('Cannot contact solr server.');
      }

      // Create a new random index.
      $this->logger()->notice("Creating temporary index...");
      $module_root = \Drupal::service('extension.list.module')->getPath('search_api_pantheon');
      $value = Yaml::parseFile($module_root . '/.ci/config/search_api.index.solr_index.yml');

      // Update index from config.
      if (isset($value['datasource_settings']["'entity:node'"])) {
        unset($value['datasource_settings']["'entity:node'"]);
      }
      $value['datasource_settings']['solr_document'] = [
        'id_field' => '_root_',
        'request_handler' => '',
        'default_query' => '*:*',
        'label_field' => '',
        'language_field' => '',
        'url_field' => '',
      ];
      $index_id = $value['id'] . '_' . uniqid();
      $value['id'] =  $index_id;
      $filesystem = \Drupal::service('file_system');
      $directory = 'temporary://' . $index_id;
      $filesystem = $filesystem->prepareDirectory($directory, FileSystemInterface:: CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      $yaml = Yaml::dump($value);
      file_put_contents($directory . '/search_api.index.' . $index_id . '.yml', $yaml);
      $config_source = new FileStorage($directory);
      \Drupal::service('config.installer')->installOptionalConfig($config_source);
      $index = Index::load($index_id);
      $this->logger()->notice("Temporary index created.");


      $indexSingleItemQuery = $this->indexSingleItem($index);
      var_dump($indexSingleItemQuery);
      /*$this->logger()->notice('Solr Update index with one document Response: {code} {reason}', [
            'code' => $indexSingleItemQuery->getResponse()->getStatusCode(),
            'reason' => $indexSingleItemQuery->getResponse()->getStatusMessage(),
        ]);
      if ($indexSingleItemQuery->getResponse()->getStatusCode() !== 200) {
        throw new \Exception('Cannot unable to index simple item. Have you created an index for the server?');
      }*/

      $indexedStats = $this->pantheonGuzzle->getQueryResult('admin/luke', [
            'query' => [
                'stats' => 'true',
            ],
        ]);
      if ($this->output()->isVerbose()) {
        $this->logger()->notice('Solr Index Stats: {stats}', [
              'stats' => print_r($indexedStats['index'], TRUE),
          ]);
      }
      else {
        $this->logger()->notice('We got Solr stats ✅');
      }
      $beans = $this->pantheonGuzzle->getQueryResult('admin/mbeans', [
            'query' => [
                'stats' => 'true',
            ],
        ]);
    }
    catch (\Exception $e) {
      \Kint::dump($e);
      $this->logger->emergency("There's a problem somewhere...");
      exit(1);
    }
    catch (\Throwable $t) {
      \Kint::dump($t);
      $this->logger->emergency("There's a problem somewhere...");
      exit(1);
    }
    finally {
      if ($index) {
        $this->logger()->notice('Removing index {index_id}', [
          'index_id' => $index->id(),
        ]);
        //$index->delete();
      }
    }
    $this->logger()->notice(
          "If there's an issue with the connection, it would have shown up here. You should be good to go!"
      );
  }

  /**
   * Pings the Solr host.
   *
   * @usage search-api-pantheon:ping
   *   Ping the solr server.
   *
   * @command search-api-pantheon:ping
   * @aliases sapp
   *
   * @return \Solarium\Core\Query\Result\ResultInterface|\Solarium\QueryType\Ping\Result|void
   *   The result.
   */
  public function pingSolrHost() {
    try {
      $ping = $this->solr->createPing();
      return $this->solr->ping($ping);
    }
    catch (\Exception $e) {
      exit($e->getMessage());
    }
    catch (\Throwable $t) {
      exit($t->getMessage());
    }
  }

  /**
   * Indexes a single item.
   *
   * @return \Solarium\Core\Query\Result\ResultInterface|\Solarium\QueryType\Update\Result
   *   The result.
   */
  protected function indexSingleItem(Index $index) {
    $data = [
      'id' => uniqid(),
      'population' => 120000,
      'name' => 'example doc',
      'countries' => ['NL', 'UK', 'US'],
      'dummy' => 10,
    ];

    return $index->indexSpecificItems([$data]);
  }

}
