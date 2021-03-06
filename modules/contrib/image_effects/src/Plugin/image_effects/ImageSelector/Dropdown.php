<?php

namespace Drupal\image_effects\Plugin\image_effects\ImageSelector;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Url;
use Drupal\image_effects\Plugin\ImageEffectsPluginBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Dropdown image selector plugin.
 *
 * Provides access to a list of images stored in a directory, specified in
 * configuration.
 *
 * @Plugin(
 *   id = "dropdown",
 *   title = @Translation("Dropdown image selector"),
 *   short_title = @Translation("Dropdown"),
 *   help = @Translation("Access a list of images stored in the directory specified in configuration.")
 * )
 */
class Dropdown extends ImageEffectsPluginBase {

  /**
   * The image factory service.
   *
   * @var \Drupal\Core\Image\ImageFactory
   */
  protected $imageFactory;

  /**
   * Constructs a ImageEffectsPluginBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Routing\UrlGeneratorInterface $url_generator
   *   The URL generator.
   * @param \Psr\Log\LoggerInterface $logger
   *   The image_effects logger.
   * @param \Drupal\Core\Image\ImageFactory $image_factory
   *   The image factory service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config_factory, UrlGeneratorInterface $url_generator, LoggerInterface $logger, ImageFactory $image_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $config_factory, $url_generator, $logger);
    $this->imageFactory = $image_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('url_generator'),
      $container->get('logger.channel.image_effects'),
      $container->get('image.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return array('path' => '');
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, array $ajax_settings = []) {
    $element['path'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Path'),
      '#default_value' => $this->configuration['path'],
      '#element_validate' => array(array($this, 'validatePath')),
      '#maxlength' => 255,
      '#description' =>
        $this->t('Location of the directory where the background images are stored.') . ' ' .
        $this->t('Relative paths will be resolved relative to the Drupal installation directory.'),
    );
    return $element;
  }

  /**
   * Validation handler for the 'path' element.
   */
  public function validatePath($element, FormStateInterface $form_state, $form) {
    if (!is_dir($element['#value'])) {
      $form_state->setErrorByName(implode('][', $element['#parents']), $this->t('Invalid directory specified.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function selectionElement(array $options = array()) {
    // Get list of images.
    $image_files = $this->getList();
    if (empty($image_files)) {
      drupal_set_message($this->t(
          'No images available. Make sure at least one image is available in the directory specified in the <a href=":url">configuration page</a>.',
          [':url' => Url::fromRoute('image_effects.settings')->toString()]
        ), 'warning'
      );
    }

    // Strip the path from the URI.
    $options['#default_value'] = isset($options['#default_value']) ? pathinfo($options['#default_value'], PATHINFO_BASENAME) : '';

    // Element.
    return array_merge([
      '#type' => 'select',
      '#title' => $this->t('Image'),
      '#description' => $this->t('Select an image.'),
      '#options' => array_combine($image_files, $image_files),
      '#element_validate' => array(array($this, 'validateSelectorUri')),
    ], $options);
  }

  /**
   * Validation handler for the selection element.
   */
  public function validateSelectorUri($element, FormStateInterface $form_state, $form) {
    if (!empty($element['#value'])) {
      if (file_exists($file_path = $this->configuration['path'] . '/' . $element['#value'])) {
        $form_state->setValueForElement($element, $file_path);
      }
      else {
        $form_state->setErrorByName(implode('][', $element['#parents']), $this->t('The selected file does not exist.'));
      }
    }
  }

  /**
   * Returns an array of files with image extensions in the specified directory.
   *
   * @return array
   *   Array of image files.
   */
  protected function getList() {
    $filelist = array();
    if (is_dir($this->configuration['path']) && $handle = opendir($this->configuration['path'])) {
      while ($file = readdir($handle)) {
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        if (in_array($extension, $this->imageFactory->getSupportedExtensions())) {
          $filelist[] = $file;
        }
      }
      closedir($handle);
    }
    return $filelist;
  }

}
