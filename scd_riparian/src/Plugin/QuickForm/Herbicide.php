<?php

declare(strict_types=1);

namespace Drupal\scd_riparian\Plugin\QuickForm;

use Drupal\Core\Form\FormStateInterface;

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
      '#required' => TRUE,
    ];

    $form['record_data']['product']= [
      '#type' => 'fieldset',
      '#title' => $this->t('Product'),
    ];
    $material_types = $this->entityTypeManager->getStorage('taxonomy_term')->loadTree('material_type');
    $material_type_options = [];
    foreach ($material_types as $term) {
      $material_type_options[$term->tid] = $term->name;
    }
    $form['record_data']['product']['group'] = $this->buildInlineContainer();
    $form['record_data']['product']['group']['material'] = [
      '#type' => 'select',
      '#title' => $this->t('Herbicide product'),
      '#options' => $material_type_options,
    ];
    $form['record_data']['product']['group']['total_applied'] = $this->buildQuantityField([
      'title' => $this->t('Total product applied'),
      'measure' => ['#value' => 'volume'],
      'units' => ['#value' => 'ounces'],
      'value' => ['#min' => 0, '#step' => 0.05],
    ]);

    $form['record_data']['product']['group']['concentration'] = $this->buildQuantityField([
      'title' => $this->t('Product concentration'),
      'measure' => ['#value' => 'ratio'],
      'units' => ['#value' => 'oz/gal'],
      'value' => ['#min' => 0, '#step' => 0.05],
    ]);

    $form['record_data']['product']['acres'] = $this->buildInlineContainer();
    $form['record_data']['product']['acres']['acres_treated'] = $this->buildQuantityField([
      'title' => $this->t('Acres treated'),
      'measure' => ['#value' => 'area'],
      'units' => ['#value' => 'acres'],
      'value' => ['#min' => 0],
    ]);

    $form['record_data']['product']['acres']['rate_per_acre'] = $this->buildQuantityField([
      'title' => $this->t('Rate per acre'),
      'measure' => ['#value' => 'rate'],
      'units' => ['#options' => [
        'oz/acre' => 'oz/acre',
        'oz/gal/acre' => 'oz/gal/acre',
      ]],
      'value' => ['#min' => 0, '#step' => 0.05],
    ]);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function prepareLog(array $form, FormStateInterface $form_state): array {
    $log = parent::prepareLog($form, $form_state);

    // Add data to individual record logs.
    if ($form_state->getValue('schedule') == 'record') {
      $log['quantity'][] = $form_state->getValue('air_temp');
      $log['quantity'][] = $form_state->getValue('wind_speed');

      // Save wind direction to notes.
      $old_notes = $log['notes'] ?? '';
      $log['notes'] = "Wind direction: {$form_state->getValue('wind_direction')}\n\n$old_notes";

      // Save material term for material quantities.
      $material_id = $form_state->getValue('material');
      $material = $this->entityTypeManager->getStorage('taxonomy_term')->load($material_id);

      // Product quantities.
      $total_applied = $form_state->getValue('total_applied');
      $total_applied['type'] = 'material';
      $total_applied['material_type'] = $material;
      $log['quantity'][] = $total_applied;

      $concentration = $form_state->getValue('concentration');
      $concentration['type'] = 'material';
      $concentration['material_type'] = $material;
      $log['quantity'][] = $concentration;

      // Acre quantities.
      $acres_treated = $form_state->getValue('acres_treated');
      $acres_treated['type'] = 'material';
      $acres_treated['material_type'] = $material;
      $log['quantity'][] = $acres_treated;

      $rate_per_acre = $form_state->getValue('rate_per_acre');
      $rate_per_acre['type'] = 'material';
      $rate_per_acre['material_type'] = $material;
      $log['quantity'][] = $rate_per_acre;
    }

    return $log;
  }

}
