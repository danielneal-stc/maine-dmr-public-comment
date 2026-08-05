<?php

namespace Drupal\dmr_public_comment\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\dmr_public_comment\Service\PegaCaseService;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin settings form for the Pega connection.
 *
 * Stores the Pega base URL, the OAuth 2.0 client ID, and the machine name of
 * the Key entity that holds the OAuth client secret. The secret itself is
 * never entered here and is never written to configuration: it lives in a
 * server environment variable or a protected file, read through the Key module
 * at request time.
 *
 * The base URL is validated against the allowlist in
 * dmr_public_comment.settings:base_url_allowlist. That allowlist is not
 * editable here on purpose, so changing which hosts this site may call
 * requires a deployment or a drush command rather than a form submission.
 *
 * Accessible at: /admin/config/dmr-public-comment
 */
class PegaSettingsForm extends ConfigFormBase {

  /**
   * Resolves the Key entity that holds the OAuth client secret.
   *
   * Used to check the chosen key's provider at validation time, so a key that
   * would put the secret back into exportable configuration is refused before
   * it can be saved.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected KeyRepositoryInterface $keyRepository;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->keyRepository = $container->get('key.repository');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['dmr_public_comment.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dmr_public_comment_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('dmr_public_comment.settings');

    $allowlist = $config->get('base_url_allowlist');
    $allowlist = is_array($allowlist) ? array_filter(array_map('strval', $allowlist)) : [];

    $form['connection'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Pega connection'),
    ];
    $form['connection']['base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Base URL'),
      '#default_value' => $config->get('base_url'),
      '#required' => TRUE,
      '#description' => $this->t('e.g. https://maine-dmr-dt6.pegacloud.net (no trailing slash). Must use https and must be one of the approved hosts: @hosts. To change that list, edit base_url_allowlist in dmr_public_comment.settings (drush config:set) or override it in settings.php.', [
        '@hosts' => $allowlist ? implode(', ', $allowlist) : $this->t('none configured'),
      ]),
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
    $form['credentials']['client_secret_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('Client Secret key'),
      '#default_value' => $config->get('client_secret_key'),
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select a key -'),
      '#description' => $this->t('Select the key that holds the OAuth client secret. Create it first at Configuration > System > Keys (/admin/config/system/keys) using the <em>Environment</em> or <em>File</em> key provider. Keys that use the <em>Configuration</em> provider are rejected, because that provider stores the secret in exportable configuration.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Validates the base URL against the allowlist, and the key's provider.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $config    = $this->config('dmr_public_comment.settings');
    $allowlist = $config->get('base_url_allowlist');
    $allowlist = is_array($allowlist) ? $allowlist : [];

    $baseUrl = rtrim(trim((string) $form_state->getValue('base_url')), '/');
    if (!PegaCaseService::isAllowedBaseUrl($baseUrl, $allowlist)) {
      $form_state->setErrorByName('base_url', $this->t('The base URL must use https, must have no path, port, or credentials, and its host must be one of the approved Pega hosts: @hosts. Update base_url_allowlist in dmr_public_comment.settings if a new Pega host has been provisioned.', [
        '@hosts' => $allowlist ? implode(', ', array_map('strval', $allowlist)) : $this->t('none configured'),
      ]));
    }

    $keyId = trim((string) $form_state->getValue('client_secret_key'));
    if ($keyId === '') {
      // Handled by #required, but keep the guard explicit.
      return;
    }

    $key = $this->keyRepository->getKey($keyId);
    if ($key === NULL) {
      $form_state->setErrorByName('client_secret_key', $this->t('The selected key no longer exists. Create the key at /admin/config/system/keys and try again.'));
      return;
    }

    if (PegaCaseService::keyStoresSecretInConfig($key)) {
      $form_state->setErrorByName('client_secret_key', $this->t('The selected key uses a key provider that stores its value inside Drupal (Configuration puts it into exportable config, State puts it in the database). Edit the key to use the Environment or File provider instead.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Only the base URL, client ID, and the name of the key are saved. The
    // client secret itself is never written to configuration.
    $this->config('dmr_public_comment.settings')
      ->set('base_url', rtrim(trim((string) $form_state->getValue('base_url')), '/'))
      ->set('client_id', trim((string) $form_state->getValue('client_id')))
      ->set('client_secret_key', trim((string) $form_state->getValue('client_secret_key')))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
