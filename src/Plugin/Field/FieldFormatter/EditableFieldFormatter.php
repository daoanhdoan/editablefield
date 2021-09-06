<?php

namespace Drupal\editablefield\Plugin\Field\FieldFormatter;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\FormatterPluginManager;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\editablefield\EditableFieldShield;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'editablefield_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "editablefield_formatter",
 *   module = "editablefield",
 *   label = @Translation("Editable Field"),
 *   field_types = {},
 *   quickedit = {
 *     "editor" = "form"
 *   }
 * )
 */
class EditableFieldFormatter extends FormatterBase
{
  /**
   * @var EntityTypeManagerInterface
   */
  protected $entityTypeManager;
  /**
   * @var FieldTypePluginManagerInterface
   */
  protected $fieldTypeManager;
  /**
   * @var FormatterPluginManager
   */
  protected $fieldFormatterManager;
  /**
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;
  protected $entityDisplayRepository;
  /**
   * Stores the tempstore factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $tempStoreFactory;

  /**
   * Construct a EditInPlaceFieldReferenceFormatter object
   *
   * @param $plugin_id
   * @param $plugin_definition
   * @param FieldDefinitionInterface $field_definition
   * @param array $settings
   * @param $label
   * @param $view_mode
   * @param array $third_party_settings
   * @param EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service
   * @param FieldTypePluginManagerInterface $field_type_manager
   * @param FormatterPluginManager $field_formatter_manager
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings
    , EntityTypeManagerInterface $entity_type_manager, FieldTypePluginManagerInterface $field_type_manager, FormatterPluginManager $field_formatter_manager, EntityDisplayRepositoryInterface $entity_display_repository)
  {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);

    $this->entityTypeManager = $entity_type_manager;
    $this->fieldTypeManager = $field_type_manager;
    $this->fieldFormatterManager = $field_formatter_manager;
    $this->formBuilder = \Drupal::service('form_builder');
    $this->entityDisplayRepository = $entity_display_repository;
    $this->tempStoreFactory = \Drupal::service('tempstore.private');
  }


  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
  {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.field.field_type'),
      $container->get('plugin.manager.field.formatter'),
      $container->get('entity_display.repository')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings()
  {
    $options = parent::defaultSettings();
    $options += array(
      'click_to_edit' => TRUE,
      'click_to_edit_style' => 'hover',
      'empty_text' => '&nbsp;',
      'fallback_format' => NULL,
      'fallback_settings' => array(),
      'hide_submit_button' => FALSE
    );
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state)
  {
    $form = parent::settingsForm($form, $form_state);
    $formatters = $this->fieldFormatterManager->getOptions($this->fieldDefinition->getType());
    unset($formatters['editablefield_formatter']);
    $field_name = $this->fieldDefinition->getName();

    if ($form_state->get('form_id') == 'views_ui_config_item_form') {
      $field_name = $form_state->get('id');
    }

    $settings = $form_state->get(['formatter_settings', $field_name]);
    if ($settings) {
      $this->setSettings($settings);
    }

    $form['hide_submit_button'] = array(
      '#type' => 'checkbox',
      '#title' => t('Hide submit button'),
      '#default_value' => (bool)$this->getSetting('hide_submit_button'),
    );

    $form['click_to_edit'] = array(
      '#type' => 'checkbox',
      '#title' => t('Click to edit'),
      '#default_value' => (bool)$this->getSetting('click_to_edit'),
    );

    $form['click_to_edit_style'] = array(
      '#type' => 'select',
      '#title' => t('Click to edit style'),
      '#options' => array(
        'button' => t('Button'),
        'hover' => t('Hover'),
      ),
      '#default_value' => $this->getSetting('click_to_edit_style'),
    );

    $form['empty_text'] = array(
      '#type' => 'textarea',
      '#rows' => 3,
      '#title' => t('Empty text'),
      '#description' => t('Text to show when the field is empty.'),
      '#default_value' => $this->getSetting('empty_text'),
    );

    $form['fallback_format'] = array(
      '#type' => 'select',
      '#title' => t('Fallback formatter'),
      '#options' => $formatters,
      '#default_value' => $this->getSetting('fallback_format'),
    );

    // Refresh the form automatically when we know which context we are in.
    $complete_form = $form_state->getCompleteForm();
    if (!empty($complete_form) && $complete_form['#form_id'] == 'entity_view_display_edit_form') {
      // Field UI.
      $form['fallback_format'] += array(
        '#field_name' => $this->fieldDefinition->getName(),
        '#op' => 'edit',
        '#executes_submit_callback' => TRUE,
        '#submit' => [[get_called_class(), 'ajaxFormMultiStepSubmit']],
        '#ajax' => array(
          'callback' => '::multistepAjax',
          'wrapper' => 'field-display-overview-wrapper',
          'effect' => 'fade',
        ),
      );

      $form['click_to_edit_style'] += array(
        '#states' => array(
          'visible' => array(
            ':input[name="fields[' . $field_name . '][settings_edit_form][settings][click_to_edit]"]' => array('checked' => TRUE),
          ),
        ),
      );
    }
    if ($form_state->get('form_id') == 'views_ui_config_item_form') {
      // Views UI.
      $form['fallback_format'] += array(
        '#ajax' => array(
          'url' => views_ui_build_form_url($form_state),
        ),
        '#submit' => [[$this, 'submitTemporaryForm']],
        '#executes_submit_callback' => TRUE,
      );
      $form['click_to_edit_style'] += array(
        '#states' => [
          'visible' => [
            ':input[name="options[settings][click_to_edit]"]' => ['checked' => TRUE],
          ],
        ]
      );
    }
    $form['fallback_settings'] = ['#value' => []];
    $instance = $this->getFallbackFormatInstance();
    if ($instance && method_exists($instance, 'settingsForm')) {
      if ($settings_form = $instance->settingsForm($form, $form_state)) {
        $form['fallback_settings'] = $settings_form;
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary()
  {
    $summary = [];
    $settings = array(
      'hide_submit_button' => 'Hide submit button',
      'click_to_edit' => 'Click to edit',
      'click_to_edit_style' => 'Click to edit style',
      'empty_text' => 'Empty text',
      'fallback_format' => 'Fallback format',
    );
    foreach ($settings as $key => $title) {
      $value = $this->getSetting($key);
      if ($key == 'click_to_edit') {
        $value = !empty($value) ? t("Enable") : t("Disable");
      }
      if ($key == 'hide_submit_button') {
        $value = !empty($value) ? t("Hide") : t("Show");
      }
      if ($value && !is_array($value)) {
        $summary[] = $this->t($title . ": @value", [
          '@value' => $value,
        ]);
      }
    }

    $instance = $this->getFallbackFormatInstance();
    if ($instance && method_exists($instance, 'settingsSummary')) {
      if ($settingsSummary = $instance->settingsSummary()) {
        $summary[] = t('Fallback Settings:');
        /** @var \Drupal\Core\StringTranslation\TranslatableMarkup $setting */
        foreach ($settingsSummary as $setting) {
          $summary[] = $setting;
        }
      }
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function getFallbackFormatInstance() {
    $fallback_format = $this->getSetting('fallback_format');
    $instance = NULL;
    if (!empty($fallback_format)) {
      $fallback_formatter = $this->fieldFormatterManager->getDefinition($fallback_format);
      $defaultSettings = call_user_func([$fallback_formatter['class'], 'defaultSettings']);
      $fallback_settings = $this->getSetting('fallback_settings') + (!empty($defaultSettings) ? $defaultSettings : []);

      $options = [
        'configuration' => [
          'type' => $fallback_format,
          'settings' => $fallback_settings,
        ],
        'view_mode' => $this->viewMode,
        'field_definition' => $this->fieldDefinition
      ];
      $instance = $this->fieldFormatterManager->getInstance($options);
    }
    return $instance;
  }

  /**
   * Form submit handler for AJAX change of the fallback formatter.
   */
  public static function ajaxFormMultiStepSubmit($form, FormStateInterface &$form_state)
  {
    $trigger = $form_state->getTriggeringElement();
    // Store the saved settings.
    $field_name = $trigger['#field_name'];
    $values = NestedArray::getValue($form_state->getValues(), ['fields', $field_name, 'settings_edit_form', 'settings']);
    $form_state->set(['formatter_settings', $field_name], $values);
  }

  /**
   * A submit handler that is used for storing temporary items when using
   * multi-step changes, such as ajax requests.
   */
  public function submitTemporaryForm(&$form, FormStateInterface &$form_state)
  {
    /** @var \Drupal\views\Plugin\views\field\FieldPluginBase $handler */
    $values = NestedArray::getValue($form_state->getValues(), ['options', 'settings']);

    $fallback_formatter = \Drupal::service('plugin.manager.field.formatter')->getDefinition($values['fallback_format']);
    $defaultSettings = call_user_func([$fallback_formatter['class'], 'defaultSettings']);
    $values['fallback_settings'] = $defaultSettings;
    NestedArray::setValue($form_state->getValues(), ['options', 'settings'], $values);

    $handler = &$form_state->get('handler');
    if ($handler) {
      // Run it through the handler's submit function.
      $handler->submitTemporaryForm($form, $form_state);
    }

    $form_state->set(['formatter_settings', $form_state->get('id')], $values);
    editablefield_form_views_ui_config_item_form_submit($form, $form_state);
  }

  public function fallbackFormatter(FieldItemListInterface $items, $langcode)
  {
    $settings = $this->getSettings();
    $display_options = [
      'type' => $settings['fallback_format'],
      'settings' => $settings['fallback_settings'],
      'label' => $this->label,
      'view_mode' => $this->viewMode
    ];

    return $items->view($display_options);
  }

  /**
   * @inheritDoc
   */
  public function viewElements(FieldItemListInterface $items, $langcode)
  {
    $element = [];
    $entity = $items->getEntity();
    $field_name = $items->getFieldDefinition()->getName();
    if (($this->viewMode == '_custom') || !$entity->access('update') || !$items->access('edit') || !\Drupal::currentUser()->hasPermission('use editable field')) {
      // Can't edit.
      $element = $this->fallbackFormatter($items, $langcode);
    }
    else {
      $display = EntityViewDisplay::collectRenderDisplay($entity, $this->viewMode)->getComponent($field_name);
      $form_state = (new FormState())
        ->set('langcode', $langcode)
        ->set('view_mode', $this->viewMode)
        ->disableRedirect()
        ->setRequestMethod('POST')
        ->setCached(TRUE)
        ->addBuildInfo('args', [$entity, $field_name, $display]);
      $element = $this->formBuilder->buildForm('Drupal\editablefield\Form\EditableFieldForm', $form_state);
      $element['#id'] = $element['#build_id'];
    }
    return $element;
  }
}
