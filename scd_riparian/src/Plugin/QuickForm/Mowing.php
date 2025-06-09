<?php

declare(strict_types=1);

namespace Drupal\scd_riparian\Plugin\QuickForm;

/**
 * @QuickForm(
 *   id = "riparian_mowing",
 *   label = @Translation("Mowing"),
 *   description = @Translation("Record riparian mowing activities."),
 *   helpText = @Translation("Use this form to record riparian mowing activities."),
 *   permissions = {},
 * )
 */
class Mowing extends RiparianMaintenanceBase {

  /**
   * {@inheritdoc}
   */
  protected string $logType = 'activity';

  /**
   * {@inheritdoc}
   */
  protected string $maintenanceLabel = 'mowing';

}
