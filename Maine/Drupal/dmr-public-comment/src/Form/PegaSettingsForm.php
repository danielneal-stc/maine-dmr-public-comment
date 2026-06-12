<?php

namespace Drupal\dmr_public_comment\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Admin settings form for the Pega connection credentials.
 *
 * Stores the Pega base URL and OAuth 2.0 client ID / secret in Drupal's
 * configuration system under the key dmr_public_comment.settings.
 *
 * Accessible at: /admin/config/dmr-public-comment
 */
class PegaSettingsForm extends ConfigFormBase {

  protected function getEditableConfigNames(): array {
    return ['dmr_public_comment.settings'];
  }

  public function getFormId(): string {
    return 'dmr_public_comment_settings';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('dmr_public_comment.settings');

    $form['connection'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Pega connection'),
    ];
    $form['connection']['base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Base URL'),
      '#default_value' => $config->get('base_url'),
      '#required' => TRUE,
      '#description' => $this->t('e.g. https://maine-dmr-dt6.pegacloud.net — no trailing slash.'),
    ];

    $form['credentials'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('OAuth credentials'),
    ];
    $form['credentials']['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#default_value' => $config->get('client_id'),
      '#required' => TRUE,
    ];
    $form['credentials']['client_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Client Secret'),
      '#description' => $config->get('client_secret')
        ? $this->t('A secret is already configured. Leave blank to keep it.')
        : $this->t('Enter the OAuth client secret.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('dmr_public_comment.settings');
    $config
      ->set('base_url', rtrim($form_state->getValue('base_url'), '/'))
      ->set('client_id', $form_state->getValue('client_id'));

    $secret = $form_state->getValue('client_secret');
    if ($secret) {
      $config->set('client_secret', $secret);
    }

    $config->save();
    parent::submitForm($form, $form_state);
  }

}
