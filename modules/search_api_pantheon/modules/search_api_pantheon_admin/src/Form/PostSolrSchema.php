<?php

namespace Drupal\search_api_pantheon_admin\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\ServerInterface;
use Drupal\search_api_pantheon\Services\SchemaPoster;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Solr admin form.
 *
 * @package Drupal\search_api_pantheon\Form
 */
class PostSolrSchema extends FormBase {

  /**
   * The PantheonGuzzle service.
   *
   * @var \Drupal\search_api_pantheon\Services\SchemaPoster
   */
  protected SchemaPoster $schemaPoster;

  /**
   * Constructs a new EntityController.
   */
  public function __construct(SchemaPoster $schemaPoster) {
    $this->schemaPoster = $schemaPoster;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
          $container->get('search_api_pantheon.schema_poster'),
      );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'search_api_solr_admin_post_schema';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ServerInterface $search_api_server = NULL) {
    $form['path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Path to config files to post'),
      '#description' => $this->t('Path to the config files to post. This should be a directory containing the configuration files to post. Leave empty to use search_api_solr defaults.'),
      '#default_value' => '',
      '#required' => FALSE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Post Schema'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $path = $form_state->getValue('path');
    $files = [];
    if ($path) {

    }
    $message = $this->schemaPoster->postSchema($search_api_server->id(), $files);
    $this->messenger()->${$message[0]}($message[1]);
  }

}
