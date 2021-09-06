<?php

namespace Drupal\editablefield\Form;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Template\Attribute;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\eck\Entity\EckEntity;
use Drupal\user\Entity\User;
use Drupal\views\Entity\Render\EntityTranslationRenderTrait;
use Drupal\views\Plugin\views\field\FieldHandlerInterface;
use Drupal\views\ViewExecutable;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds and process a form for editing a single entity field.
 *
 * @internal
 */
class EditableFieldForm extends FormBase
{
  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The node type storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $nodeTypeStorage;

  /**
   * The entity display repository service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * EditableFieldForm constructor.
   * @param EntityTypeManagerInterface $entity_type_manager
   * @param EntityRepositoryInterface $entity_repository
   * @param ModuleHandlerInterface $module_handler
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityRepositoryInterface $entity_repository, ModuleHandlerInterface $module_handler)
  {
    $this->moduleHandler = $module_handler;
    $this->entityTypeManager = $entity_type_manager;
    $this->nodeTypeStorage = $this->entityTypeManager->getStorage('node_type');
    $this->entityRepository = $entity_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity.repository'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'editablefield_form';
  }

  /**
   * {@inheritdoc}
   */
  public static function getFormDisplay($entity, $field_name)
  {
    // Fetch the display used by the form. It is the display for the 'default'
    // form mode, with only the current field visible.
    $display = EntityFormDisplay::collectRenderDisplay($entity, 'default');
    foreach ($display->getComponents() as $name => $options) {
      if ($name != $field_name) {
        $display->removeComponent($name);
      }
    }
    return $display;
  }

  /**
   * {@inheritdoc}
   *
   * Builds a form for a single entity field.
   */
  public function buildForm(array $form, FormStateInterface $form_state, EntityInterface $entity = NULL, $field_name = NULL, $display = NULL)
  {
    return $this->form($form, $form_state, $entity, $field_name, $display);
  }

  /**
   *
   */
  public function form(array $form, FormStateInterface &$form_state, EntityInterface $entity = NULL, $field_name = NULL, $display = NULL)
  {
    /** @var EntityInterface $entity */
    $entity = $entity ?: $form_state->get('entity');
    $field_name = $field_name ?: $form_state->get('field_name');
    $display = $display ?: $form_state->get('display');

    $entity_type = $entity->getEntityTypeId();
    $id = $entity->id();
    $vid = $entity->getEntityType()->isRevisionable() ? $entity->getLoadedRevisionId() : 0;
    $langcode = $form_state->get('langcode');
    $view_mode_id = $form_state->get('view_mode');

    // Get latest saved Entity from Storage
    $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity->id());

    // Insert the field form.
    $this->init($form_state, $entity, $field_name);

    $form += array('#parents' => array($field_name));

    $form['#attached']['library'][] = 'editablefield/editablefield';
    $form['#attributes'] = array('class' => array('editable-field'));

    $form['#field_name'] = $field_name;
    $form['#entity'] = $entity;
    $form['#cache']['contexts'] = $this->getCacheContexts($entity, $langcode);
    $form['#cache']['tags'] = $this->getCacheTags($entity, $field_name);

    $wrapper_attributes = new Attribute();

    // Wrap the whole form into a wrapper, and prepare AJAX settings.
    $wrapper_id = Html::getId('editablefield-' . implode("-", $form['#parents']));
    $wrapper_attributes->setAttribute('id', $wrapper_id);

    if ($view_mode_id == "views_view" && $this->moduleHandler->moduleExists('quickedit')) {
      $data_quickedit_entity_id = $entity->getEntityTypeId() . '/' . $entity->id();
      $wrapper_attributes->setAttribute('data-quickedit-entity-id', $data_quickedit_entity_id);
    }

    $wrapper_attributes->setAttribute('class', 'editablefield-item editablefield-item-' . md5(\Drupal::time()->getCurrentMicroTime()));

    $form['#prefix'] = '<div ' . $wrapper_attributes->__toString() . '>';
    $form['#suffix'] = '</div>';
    $ajax = array(
      'callback' => 'Drupal\editablefield\Form\EditableFieldForm::updateFormAjaxCallback',
      'wrapper' => $wrapper_id,
      'effect' => 'fade',
      'event' => 'click',
      'progress' => array(
        'type' => 'throbber',
        'message' => t('Please wait'),
      ),
    );

    $edit_mode_state = $this->getEditMode($form_state, $form['#parents']);
    $edit_mode = empty($display['settings']['click_to_edit']) || $edit_mode_state;

    if ($edit_mode) {
      // Add the field form.
      $form_state->get('form_display')->buildForm($entity, $form, $form_state);

      $form['actions'] = array('#type' => 'actions');
      $form['actions']['submit'] = array(
        '#name' => 'submit-' . implode('-', $form['#parents']),
        '#type' => 'submit',
        '#value' => t('Save'),
        '#ajax' => $ajax,
        '#submit' => ['::submitForm'],
        '#limit_validation_errors' => array($form['#parents']),
        '#op' => 'save'
      );
      if (!empty($display['settings']['hide_submit_button'])) {
        $form['actions']['submit']['#attributes']['class'] = ['visually-hidden'];
      }

      // Simplify it for optimal in-place use.
      if ($view_mode_id == 'views_view') {
        $this->simplify($form, $form_state);
      }

      // Add a dummy changed timestamp field to attach form errors to.
      if ($entity instanceof EntityChangedInterface) {
        $form['changed_field'] = [
          '#type' => 'hidden',
          '#value' => $entity->getChangedTime(),
        ];
      }
    } else {
      $edit_style = isset($display['settings']['click_to_edit_style']) ? $display['settings']['click_to_edit_style'] : 'button';
      // Closure to render the field given a view mode.
      $render_field_in_view_mode = function ($view_mode_id) use ($entity, $field_name, $langcode, $display) {
        return $this->renderField($entity, $field_name, $langcode, $view_mode_id, $display);
      };

      // Re-render the updated field.
      $output = $render_field_in_view_mode($view_mode_id);

      if ($output) {
        $form['field'] = $output;
        $items = $entity->get($field_name);
        // Click to edit mode: generate a AJAX-bound submit handler.
        $form['actions']['submit'] = array(
          '#name' => implode('-', array_merge($form['#parents'], ['editablefield', 'edit', $field_name])),
          '#type' => 'submit',
          '#value' => t('Edit this field'),
          '#submit' => [[get_class($this), 'submitEditMode']],
          '#ajax' => $ajax,
          '#limit_validation_errors' => array($form['#parents']),
          '#op' => 'edit',
          '#access' => ($entity->access('update') && $items->access('edit')) && \Drupal::currentUser()->hasPermission('use editable field'),
          '#attributes' => array(
            'class' => array(
              'editablefield-edit',
              'editablefield-edit-' . $edit_style,
            ),
          ),
        );
      }
    }

    // Specify the form build id to allow drupal to find the form cache.
    $form['#build_id'] = 'editablefield_form__' . $entity_type . '__' . $id . '__' . $vid . '__' . $field_name;
    return $form;
  }

  /**
   * Ajax callback: process an Ajax submission of the form.
   */
  public static function updateFormAjaxCallback($form, FormStateInterface &$form_state)
  {
    // Return the proper part of the form.
    $parents = $form_state->getTriggeringElement()['#array_parents'];
    // Remove the 'actions' and 'link' elements.
    $parents = array_slice($parents, 0, -2);
    $element = NestedArray::getValue($form, $parents);
    return !empty($element) ? $element : ['#markup' => t('No process!')];
  }

  /**
   * Form submit callback: switch to edit mode.
   */
  public static function submitEditMode(&$form, FormStateInterface &$form_state)
  {
    // Remove the 'actions' and 'link' elements.
    $parents = $form_state->getTriggeringElement()['#array_parents'];
    $parents = array_slice($parents, 0, -2);
    self::setEditMode($form_state, TRUE, $parents);
    $form_state->setRebuild();
  }

  /**
   * Renders a field.
   *
   * If the view mode ID is not an Entity Display view mode ID, then the field
   * was rendered using a custom render pipeline (not the Entity/Field API
   * render pipeline).
   *
   * An example could be Views' render pipeline. In that case, the view mode ID
   * would probably contain the View's ID, display and the row index.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being edited.
   * @param string $field_name
   *   The name of the field that is being edited.
   * @param string $langcode
   *   The name of the language for which the field is being edited.
   * @param string $view_mode_id
   *   The view mode the field should be rerendered in. Either an Entity Display
   *   view mode ID, or a custom one. See hook_quickedit_render_field().
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   Rendered HTML.
   *
   * @see hook_editablefield_render_field()
   */
  public function renderField(EntityInterface $entity, $field_name, $langcode, $view_mode_id, $display)
  {
    $output = [];
    $entity = $this->entityRepository->getTranslationFromContext($entity, $langcode);
    // Replace entity with PrivateTempStore copy if available and not resetting,
    // init PrivateTempStore copy otherwise.

    /** @var \Drupal\Core\Field\FieldItemInterface $items */
    $items = $entity->get($field_name);
    if (!$items->isEmpty()) {
      if ($display) {
        $display_options = [
          'type' => $display['settings']['fallback_format'],
          'settings' => !empty($display['settings']['fallback_settings']) ? $display['settings']['fallback_settings'] : [],
          'label' => $display['label'],
          'view_mode' => $view_mode_id
        ];
        $output = $items->view($display_options);

      } else {
        // Each part of a custom (non-Entity Display) view mode ID is separated
        // by a dash; the first part must be the module name.
        $mode_id_parts = explode('-', $view_mode_id, 2);
        $module = reset($mode_id_parts);
        $args = [$entity, $field_name, $view_mode_id, $langcode, $display];
        $output = $this->moduleHandler->invoke($module, 'editablefield_render_field', $args);
      }
    } elseif ($view_mode_id == 'views_view' && !empty($display['settings']['empty_text'])) {
      $output = array(
        '#markup' => $display['settings']['empty_text'],
      );
    }

    return $output;
  }

  /**
   * Gets the edit mode of an editable field in form.
   *
   * @param $form_state
   *   A keyed array containing the current state of the form.
   * @param $parents
   *   (optional) An array of parent form elements. Default to empty.
   *
   * @return
   *   TRUE if the field is in edit mode, FALSE otherwise.
   */
  public static function getEditMode(FormStateInterface $form_state, $parents = array())
  {
    if (!$form_state->get('edit_mode')) {
      return FALSE;
    }

    if (!empty($parents) && is_array($form_state->get('edit_mode'))) {
      return NestedArray::getValue($form_state->get('edit_mode'), $parents);
    }
    return (bool)$form_state->get('edit_mode');
  }

  /**
   * Sets the edit mode of an editable field in form.
   *
   * @param $form_state
   *   A keyed array containing the current state of the form.
   * @param $value
   *   Edit mode value, either TRUE or FALSE.
   * @param $parents
   *   (optional) An array of parent form elements. Default to empty.
   */
  public static function setEditMode(&$form_state, $value, $parents = array())
  {
    if (!empty($parents)) {
      if (empty($form_state->get('edit_mode')) || !is_array($form_state->get('edit_mode'))) {
        $form_state->set('edit_mode', []);
      }
      $edit_mode = &$form_state->get('edit_mode');
      NestedArray::setValue($edit_mode, $parents, $value);
    } else {
      $form_state->set('edit_mode', $value);
    }
  }

  /**
   * Initialize the form state and the entity before the first form build.
   */
  protected function init(FormStateInterface $form_state, EntityInterface $entity, $field_name)
  {
    // @todo Rather than special-casing $node->revision, invoke prepareEdit()
    //   once https://www.drupal.org/node/1863258 lands.
    if ($entity->getEntityTypeId() == 'node') {
      $node_type = $this->nodeTypeStorage->load($entity->bundle());
      $entity->setNewRevision($node_type->shouldCreateNewRevision());
      $entity->revision_log = NULL;
    }

    $form_state->set('entity', $entity);
    $form_state->set('field_name', $field_name);

    // Fetch the display used by the form. It is the display for the 'default'
    // form mode, with only the current field visible.
    $display = EditableFieldForm::getFormDisplay($entity, $field_name);
    $form_state->set('form_display', $display);
  }

  /**
   * Returns a cloned entity containing updated field values.
   *
   * Calling code may then validate the returned entity, and if valid, transfer
   * it back to the form state and save it.
   */
  protected function buildEntity(array $form, FormStateInterface $form_state)
  {
    /** @var $entity \Drupal\Core\Entity\EntityInterface */
    $entity = clone $form_state->get('entity');
    $field_name = $form_state->get('field_name');

    $form_state->get('form_display')->extractFormValues($entity, $form, $form_state);

    // @todo Refine automated log messages and abstract them to all entity
    //   types: https://www.drupal.org/node/1678002.
    if ($entity->getEntityTypeId() == 'node' && $entity->isNewRevision() && $entity->revision_log->isEmpty()) {
      $entity->revision_log = t('Updated the %field-name field through editable field.', ['%field-name' => $entity->get($field_name)->getFieldDefinition()->getLabel()]);
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state)
  {
    /** @var EntityInterface $entity */
    $entity = $form_state->get('entity');
    $stored_entity = $this->entityTypeManager->getStorage($entity->getEntityTypeId())->load($entity->id());
    if ($stored_entity) {
      $form_state->set('entity', $stored_entity);
    }
    $entity = $this->buildEntity($form, $form_state);
    $form_state->get('form_display')->validateFormValues($entity, $form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * Saves the entity with updated values for the edited field.
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $entity = $this->buildEntity($form, $form_state);
    $entity->save();
    $form_state->set('entity', $entity);
    // Store entity in tempstore with its UUID as tempstore key.
    $parents = $form_state->getTriggeringElement()['#array_parents'];
    $parents = array_slice($parents, 0, -2);
    self::setEditMode($form_state, FALSE, $parents);
    $form_state->setRebuild();
  }

  /**
   * Simplifies the field edit form for in-place editing.
   *
   * This function:
   * - Hides the field label inside the form, because JavaScript displays it
   *   outside the form.
   * - Adjusts textarea elements to fit their content.
   *
   * @param array &$form
   *   A reference to an associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  protected function simplify(array &$form, FormStateInterface $form_state)
  {
    $field_name = $form['#field_name'];
    $widget_element =& $form[$field_name]['widget'];
    if (!$widget_element) {
      return;
    }

    $num_children = count(Element::children($widget_element));
    if ($num_children == 0 && $widget_element['#type'] != 'checkbox') {
      $widget_element['#title_display'] = 'invisible';
    }
    if ($num_children == 1 && isset($widget_element[0]['value'])) {
      // @todo While most widgets name their primary element 'value', not all
      //   do, so generalize this.
      $widget_element[0]['value']['#title_display'] = 'invisible';
    }

    // Adjust textarea elements to fit their content.
    if (isset($widget_element[0]['value']['#type']) && $widget_element[0]['value']['#type'] == 'textarea') {
      $lines = count(explode("\n", $widget_element[0]['value']['#default_value']));
      $widget_element[0]['value']['#rows'] = $lines + 1;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(EntityInterface $entity, $langcode) {
    return Cache::mergeContexts(
      $this->entityRepository->getTranslationFromContext($entity, $langcode)->getCacheContexts(),
      ['user']
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return Cache::PERMANENT;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(EntityInterface $entity, $field_name) {
    $field_definition = $this->getBundleFieldDefinition($entity, $field_name);
    $field_storage_definition = $field_definition->getFieldStorageDefinition();

    return Cache::mergeTags(
      $field_definition instanceof CacheableDependencyInterface ? $field_definition->getCacheTags() : [],
      $field_storage_definition instanceof CacheableDependencyInterface ? $field_storage_definition->getCacheTags() : []
    );
  }

  /**
   * Collects the definition of field.
   *
   * @param string $bundle
   *   The bundle to load the field definition for.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface|null
   *   The field definition. Null if not set.
   */
  protected function getBundleFieldDefinition(EntityInterface $entity, $field_name) {
    $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions($entity->getEntityTypeId(), $entity->bundle());
    return (array_key_exists($field_name, $field_definitions)) ? $field_definitions[$field_name] : NULL;
  }

  /**
   * @inheritDoc
   */
  public function viewsForm(array $form, FormStateInterface &$form_state, ViewExecutable $view = NULL, FieldHandlerInterface $field = NULL)
  {
    $field_name = array_search($field, $view->field, TRUE);
    $form_element_name = $field_name;
    if (method_exists($field, 'form_element_name')) {
      $form_element_name = $field->form_element_name();
    }
    $method_form_element_row_id_exists = FALSE;
    if (method_exists($field, 'form_element_row_id')) {
      $method_form_element_row_id_exists = TRUE;
    }

    foreach ($view->result as $row_id => $row) {
      if ($method_form_element_row_id_exists) {
        $form_element_row_id = $field->form_element_row_id($row_id);
      } else {
        $form_element_row_id = $row_id;
      }

      $entity = $field->getEntity($row);

      if (!$entity) {
        continue;
      }

      $display = $field->options;
      $display['label'] = 'hidden';

      $form_state->set('view_mode', 'views_view');
      $form_state->set('row_id', $row_id);

      $field_form = array(
        '#parents' => array($form_element_name, $form_element_row_id),
        '#tree' => TRUE,
      );

      $field_form += $this->form($field_form, $form_state, $entity, $field->definition['field_name'], $display);
      $form_id = $this->getFormId();
      \Drupal::moduleHandler()->alter(['form', 'editablefield_views_form'], $field_form, $form_state, $form_id);
      $field_form['actions']['submit']['#submit'][] = [$this, 'submitViewsForm'];

      $form[$form_element_name][$form_element_row_id] = $field_form;
      $form['#cache']['max-age'] = 0;
    }
    return $form;
  }

  /**
   * @inheritDoc
   */
  public function submitViewsForm(array &$form, FormStateInterface $form_state)
  {
    $trigger = $form_state->getTriggeringElement();
    $parents = $trigger['#array_parents'];
    $parents = array_slice($parents, 0, -2);

    if ($trigger['#op'] == 'edit') {
      EditableFieldForm::submitEditMode($form, $form_state);
      return;
    }

    $field_form = &NestedArray::getValue($form, $parents);

    if ($field_form) {
      /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
      $entity = $field_form['#entity'];
      $field_name = $field_form['#field_name'];

      if ($entity->getEntityTypeId() == 'node') {
        $node_type = $this->entityTypeManager->getStorage('node_type')->load($entity->bundle());
        $entity->setNewRevision($node_type->shouldCreateNewRevision());
        $entity->revision_log = NULL;
      }

      $form_state->set('entity', $entity);
      /*if ($entity instanceof RevisionableInterface) {
        $vid = $entity->getLoadedRevisionId();
        if (!empty($vid) && !$entity->isDefaultRevision()) {
          $entity->setNewRevision(FALSE);
          $entity->setChangedTime(time());
        }
      }*/
      // Fetch the display used by the form. It is the display for the 'default'
      // form mode, with only the current field visible.
      $display = EditableFieldForm::getFormDisplay($entity, $field_name);
      $display->extractFormValues($entity, $field_form, $form_state);
      $display->validateFormValues($entity, $field_form, $form_state);

      if ($entity->getEntityTypeId() == 'node' && $entity->isNewRevision() && $entity->revision_log->isEmpty()) {
        $entity->revision_log = t('Updated the %field-name field through editable field.', ['%field-name' => $entity->get($field_name)->getFieldDefinition()->getLabel()]);
      }
      $entity->save();
    }

    EditableFieldForm::setEditMode($form_state, FALSE, $parents);
    $form_state->setRebuild();
  }
}
