<?php

declare(strict_types=1);

namespace Drupal\scd_riparian\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\asset\Entity\Asset;
use Drupal\geofield\GeoPHP\GeoPHPInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Provides a form for importing folders of KML placemarks as sites and segments.
 */
class SiteSegmentImporter extends FormBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected SerializerInterface $serializer,
    protected FileSystemInterface $fileSystem,
    protected GeoPHPInterface $geoPhp,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('serializer'),
      $container->get('file_system'),
      $container->get('geofield.geophp'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'scd_riparian_kml_site_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['input'] = [
      '#type' => 'details',
      '#title' => $this->t('Input'),
      '#open' => TRUE,
    ];

    $form['input']['file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('KML File'),
      '#description' => $this->t('Upload your KML file here and click "Parse".'),
      '#upload_location' => 'private://kml',
      '#upload_validators' => [
        'file_validate_extensions' => ['kml kmz'],
      ],
      '#required' => TRUE,
    ];

    $form['input']['parse'] = [
      '#type' => 'button',
      '#value' => $this->t('Parse'),
      '#ajax' => [
        'callback' => [$this, 'parseKml'],
        'wrapper' => 'output',
      ],
    ];

    // Hidden field to track if the file was parsed. This helps with validation.
    $form['input']['parsed'] = [
      '#type' => 'hidden',
      '#value' => FALSE,
    ];

    $form['output'] = [
      '#type' => 'container',
      '#prefix' => '<div id="output">',
      '#suffix' => '</div>',
    ];

    // Only generate the output if the parse button is clicked.
    // Uploading a file will trigger an ajax call.
    $file_ids = $form_state->getValue('file', []);
    $submitted = $form_state->getTriggeringElement();
    if (empty($file_ids) || !$submitted || $submitted['#parents'][0] != 'parse') {
      return $form;
    }

    // Get the uploaded file contents.
    /** @var \Drupal\file\FileInterface $file */
    $file = $this->entityTypeManager->getStorage('file')->load(reset($file_ids));
    $path = $file->getFileUri();
    if ($file->getMimeType() === 'application/vnd.google-earth.kmz' && extension_loaded('zip')) {
      $path = 'zip://' . $this->fileSystem->realpath($path) . '#doc.kml';
    }
    $data = file_get_contents($path);

    // Build an XML object.
    $xml = simplexml_load_string($data);
    if (empty($xml)) {
      return $form;
    }

    // Determine the root. Sometimes it is "Document".
    $root = $xml;
    if (isset($xml->Document)) {
      $root = $xml->Document;
    }

    // Start an array of placemarks to decode.
    $default_site_name = '';
    $placemarks = [];
    $count = 0;
    if (isset($root->Folder)) {
      $folder = $root->Folder;
      if (isset($folder->Placemark)) {
        $default_site_name = (string) $folder->name ?? '';
        foreach ($folder->Placemark as $placemark) {
          $wkt = NULL;
          $raw_geom = $placemark->asXML();
          if ($geometry = $this->geoPhp->load($raw_geom, 'kml')) {
            $wkt = $geometry->out('wkt');
          }
          $name = (string) $placemark->name ?? "$default_site_name $count";
          $placemarks[] = [$name, $wkt];
        }
      }
    }

    // Bail if no geometries were found.
    if (empty($placemarks)) {
      $this->messenger()->addWarning($this->t('No placemarks could be parsed from the uploaded file.'));
      return $form;
    }

    // Display the output details.
    $form['output']['#type'] = 'details';
    $form['output']['#title'] = $this->t('Output');
    $form['output']['#open'] = TRUE;

    $form['output']['site_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Site name'),
      '#default_value' => $default_site_name,
      '#required' => TRUE,
    ];

    // Build a tree for asset data.
    $form['output']['assets'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];
    foreach ($placemarks as $index => $placemark_data) {

      // Create a fieldset for the geometry.
      $form['output']['assets'][$index] = [
        '#type' => 'fieldset',
        '#title' => "Segment $index"
      ];

      $form['output']['assets'][$index]['name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Name'),
        '#default_value' => $placemark_data[0],
        '#required' => TRUE,
      ];

      $form['output']['assets'][$index]['notes'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Notes'),
      ];

      $form['output']['assets'][$index]['geometry'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Geometry'),
        '#default_value' => $wkt,
      ];

      $form['output']['assets'][$index]['confirm'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Create this segment'),
        '#description' => $this->t('Uncheck this if you do not want to create this asset in farmOS.'),
        '#default_value' => TRUE,
      ];
    }

    // Mark the form as parsed.
    $form['input']['parsed']['#value'] = TRUE;

    $form['output']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create assets'),
    ];

    return $form;
  }

  /**
   * Ajax callback that returns the output fieldset after parsing KML.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return mixed
   *   The elements to replace.
   */
  public function parseKml(array &$form, FormStateInterface $form_state) {
    return $form['output'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

    // Only validate if the file has been parsed.
    if (!$form_state->getValue('parsed')) {
      return;
    }

    $assets = $form_state->getValue('assets', []);
    $confirmed_assets = array_filter($assets, function ($asset) {
      return !empty($asset['confirm']);
    });

    // Set an error if no assets are selected to be created.
    if (empty($confirmed_assets)) {
      $form_state->setErrorByName('submit', $this->t('At least one asset must be created.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // Bail if no file was uploaded.
    $file_ids = $form_state->getValue('file', []);
    if (empty($file_ids)) {
      $this->messenger()->addError($this->t('File upload failed.'));
      return;
    }

    // Load the assets to create.
    $assets = $form_state->getValue('assets', []);
    $confirmed_assets = array_filter($assets, function ($asset) {
      return !empty($asset['confirm']);
    });

    // Create the parent site.
    $site_name = $form_state->getValue('site_name');
    $parent_asset = Asset::create([
      'type' => 'land',
      'land_type' => 'scd_site',
      'is_location' => TRUE,
      'is_fixed' => TRUE,
      'name' => $site_name,
    ]);
    $parent_asset->save();
    $asset_url = $parent_asset->toUrl()->setAbsolute()->toString();
    $this->messenger()->addMEssage($this->t('Created site: <a href=":url">%asset_label</a>', [':url' => $asset_url, '%asset_label' => $parent_asset->label()]));

    // Create segments.
    foreach ($confirmed_assets as $asset) {
      $new_asset = Asset::create([
        'type' => 'land',
        'land_type' => 'scd_segment',
        'intrinsic_geometry' => $asset['geometry'],
        'is_location' => TRUE,
        'is_fixed' => TRUE,
        'name' => $asset['name'],
        'notes' => $asset['notes'] ?? NULL,
        'parent' => $parent_asset,
      ]);
      $new_asset->save();
      $asset_url = $new_asset->toUrl()->setAbsolute()->toString();
      $this->messenger()->addMEssage($this->t('Created segment: <a href=":url">%asset_label</a>', [':url' => $asset_url, '%asset_label' => $new_asset->label()]));
    }
  }

}
