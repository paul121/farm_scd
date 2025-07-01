<?php

declare(strict_types=1);

namespace Drupal\scd_riparian\Plugin\QuickForm;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;

/**
 * @QuickForm(
 *   id = "riparian_herbicide",
 *   label = @Translation("Herbicide"),
 *   description = @Translation("Record riparian herbicide activities."),
 *   helpText = @Translation("Use this form to record riparian herbicide activities."),
 *   permissions = {},
 * )
 */
class Herbicide extends RiparianMaintenanceBase {

  /**
   * {@inheritdoc}
   */
  protected string $logType = 'input';

  /**
   * {@inheritdoc}
   */
  protected string $maintenanceLabel = 'herbicide';

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $id = NULL) {
    $form = parent::buildForm($form, $form_state, $id);

    $form['record_data']['weather']= [
      '#type' => 'fieldset',
      '#title' => $this->t('Weather'),
    ];
    $form['record_data']['weather']['group'] = $this->buildInlineContainer();
    $form['record_data']['weather']['group']['air_temp'] = $this->buildQuantityField([
      'title' => $this->t('Air temperature'),
      'measure' => ['#value' => 'temperature'],
      'units' => ['#value' => 'fahrenheit'],
      'value' => ['#step' => 1],
    ]);
    $form['record_data']['weather']['group']['wind_speed'] = $this->buildQuantityField([
      'title' => $this->t('Wind speed'),
      'measure' => ['#value' => 'speed'],
      'units' => ['#value' => 'mph'],
      'value' => ['#step' => 1],
    ]);

    $wind_directions = [
      $this->t('North'),
      $this->t('South'),
      $this->t('East'),
      $this->t('West'),
      $this->t('North East'),
      $this->t('North West'),
      $this->t('South East'),
      $this->t('South West'),
    ];
    $wind_direction_options = array_combine($wind_directions, $wind_directions);
    $form['record_data']['weather']['group']['wind_direction'] = [
      '#type' => 'select',
      '#title' => $this->t('Wind direction'),
      '#options' => $wind_direction_options,
    ];

    // Products section with dynamic product entries
    $form['record_data']['products'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Products'),
      '#prefix' => '<div id="products-wrapper">',
      '#suffix' => '</div>',
      '#tree' => TRUE,
    ];

    $form['record_data']['products']['acres_treated'] = $this->buildQuantityField([
      'title' => $this->t('Acres treated'),
      'measure' => ['#value' => 'area'],
      'units' => ['#value' => 'acres'],
      'value' => ['#min' => 0],
    ]);

    // Determine the number of products
    $num_products = $form_state->get('num_products');
    $num_products = $num_products ?: 1;

    $triggering_element = $form_state->getTriggeringElement();
    if ($triggering_element && $triggering_element['#parents'][1] == 'add_product') {
      $num_products += 1;
      $form_state->set('num_products', $num_products);
    }
    elseif ($triggering_element && $triggering_element['#parents'][1] == 'remove_product') {
      if ($num_products > 1) {
        $num_products -= 1;
      }
      $form_state->set('num_products', $num_products);
    }

    // Load material types for dropdown
    $material_types = $this->entityTypeManager->getStorage('taxonomy_term')->loadTree('material_type');
    $material_type_options = [];
    foreach ($material_types as $term) {
      $material_type_options[$term->tid] = $term->name;
    }

    // Add multiple product entries
    for ($i = 0; $i < $num_products; $i++) {
      $form['record_data']['products'][$i] = $this->buildProductEntry($material_type_options, $i);
    }

    // Add product button
    $form['record_data']['products']['add_product'] = [
      '#type' => 'button',
      '#value' => $this->t('Add Another Product'),
      '#ajax' => [
        'callback' => [$this, 'productsCallback'],
        'wrapper' => 'products-wrapper',
        'effect' => 'fade',
      ],
      '#limit_validation_errors' => [],
    ];
    $form['record_data']['products']['remove_product'] = [
      '#type' => 'button',
      '#value' => $this->t('Remove Product'),
      '#ajax' => [
        'callback' => [$this, 'productsCallback'],
        'wrapper' => 'products-wrapper',
        'effect' => 'fade',
      ],
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  /**
   * Build a single product entry form.
   */
  protected function buildProductEntry(array $material_type_options, int $index) {
    $product_entry = [
      '#type' => 'fieldset',
      '#title' => $this->t('Product @count', ['@count' => $index + 1]),
      '#tree' => TRUE,
    ];

    $product_entry['material'] = [
      '#type' => 'select',
      '#title' => $this->t('Herbicide product'),
      '#options' => $material_type_options,
      '#empty_option' => $this->t('- Select -'),
    ];

    $product_entry['quantities'] = $this->buildInlineContainer();
    $product_entry['quantities']['total_applied'] = $this->buildQuantityField([
      'title' => $this->t('Total product applied'),
      'measure' => ['#value' => 'volume'],
      'units' => ['#value' => 'ounces'],
      'value' => ['#min' => 0, '#step' => 0.05],
    ]);

    $product_entry['quantities']['concentration'] = $this->buildQuantityField([
      'title' => $this->t('Product concentration'),
      'measure' => ['#value' => 'ratio'],
      'units' => ['#value' => 'oz/gal'],
      'value' => ['#min' => 0, '#step' => 0.05],
    ]);

    $product_entry['quantities']['rate_per_acre'] = $this->buildQuantityField([
      'title' => $this->t('Rate per acre'),
      'measure' => ['#value' => 'rate'],
      'units' => ['#options' => [
        'oz/acre' => 'oz/acre',
        'oz/gal/acre' => 'oz/gal/acre',
      ]],
      'value' => ['#min' => 0, '#step' => 0.05],
    ]);
    return $product_entry;
  }

  /**
   * Callback for products.
   */
  public function productsCallback(array &$form, FormStateInterface $form_state) {
    return $form['record_data']['products'];
  }

  /**
   * {@inheritdoc}
   */
  protected function prepareLog(bool $modify_existing, array $form, FormStateInterface $form_state): array {
    $log = parent::prepareLog($modify_existing, $form, $form_state);

    // Add data to individual record logs.
    if ($form_state->getValue('schedule') == 'record') {
      $log['quantity'][] = $form_state->getValue('air_temp');
      $log['quantity'][] = $form_state->getValue('wind_speed');

      // Save wind direction to notes.
      $old_notes = $log['notes'] ?? '';
      $log['notes'] = "Wind direction: {$form_state->getValue('wind_direction')}\n\n$old_notes";

      // Process multiple products.
      // Save list of all materials.
      $material_ids = [];
      $material_quantities = [];
      foreach ($form_state->getValue('products') as $index => $product) {

        // Skip if no material is selected.
        if (!is_numeric($index) || empty($product['material'])) {
          continue;
        }

        // Load material term
        $material_id = $product['material'];
        $material_ids[] = $material_id;
        $material = $this->entityTypeManager->getStorage('taxonomy_term')->load($material_id);

        // Product quantities
        $total_applied = $product['quantities']['total_applied'];
        $total_applied['type'] = 'material';
        $total_applied['material_type'] = $material;
        $material_quantities[] = $total_applied;

        $concentration = $product['quantities']['concentration'];
        $concentration['type'] = 'material';
        $concentration['material_type'] = $material;
        $material_quantities[] = $concentration;

        $rate_per_acre = $product['quantities']['rate_per_acre'];
        $rate_per_acre['type'] = 'material';
        $rate_per_acre['material_type'] = $material;
        $material_quantities[] = $rate_per_acre;
      }

      // Also save acres treated as additional quantity.
      $acres_treated = $form_state->getValue(['products', 'acres_treated']);
      $acres_treated['type'] = 'material';
      $acres_treated['material_type'] = $material_ids;
      $log['quantity'][] = $acres_treated;

      // Finally, add all material quantities to the log data.
      array_push($log['quantity'], ...$material_quantities);
    }

    return $log;
  }
}
