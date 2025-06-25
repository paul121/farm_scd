<?php

declare(strict_types=1);

namespace Drupal\scd_riparian\Plugin\QuickForm;

/**
 * @QuickForm(
 *   id = "riparian_watering",
 *   label = @Translation("Watering"),
 *   description = @Translation("Record riparian watering activities."),
 *   helpText = @Translation("Use this form to record riparian watering activities."),
 *   permissions = {},
 * )
 */
class Watering extends RiparianMaintenanceBase {

  /**
   * {@inheritdoc}
   */
  protected string $logType = 'activity';

  /**
   * {@inheritdoc}
   */
  protected string $maintenanceLabel = 'watering';

}
