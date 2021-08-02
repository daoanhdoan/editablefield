<?php
namespace Drupal\editablefield\Form;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\views\Plugin\views\field\FieldHandlerInterface;
use Drupal\views\Render\ViewsRenderPipelineMarkup;
use Drupal\views\ViewExecutable;
use DrupalCodeGenerator\Command\Drupal_8\Plugin\Field\Widget;
use Symfony\Component\DependencyInjection\ContainerInterface;

class EditableFieldViewsForm extends FormBase {
  /**
   * @var EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var EditableFieldForm;
   */
  protected $editableFieldForm;

  /**
   * @inheritDoc
   */
  public function getFormId()
  {
    return "editablefield_views_form";
  }

  /**
   * EditableFieldForm constructor.
   * @param EntityTypeManagerInterface $entity_type_manager
   * @param EditableFieldForm $editablefield
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EditableFieldForm $editablefield)
  {
    $this->entityTypeManager = $entity_type_manager;
    $this->editableFieldForm = $editablefield;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static($container->get('entity_type.manager'), $container->get('editablefield.form'));
  }

  /**
   * @inheritDoc
   */
  public function buildForm(array $form, FormStateInterface $form_state, ViewExecutable $view = NULL, FieldHandlerInterface $field = NULL)
  {
    return $this->form($form,$form_state, $view, $field);
  }

  /**
   * @inheritDoc
   */
  public function form(array $form, FormStateInterface &$form_state, ViewExecutable $view = NULL, FieldHandlerInterface $field = NULL)
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
      $form_state->set('entity', $entity);
      $form_state->set('field_name', $field->definition['field_name']);
      $form_state->set('display', $display);
      $form_state->set('row_id', $row_id);

      $field_form = array(
        '#parents' => array($form_element_name, $form_element_row_id),
        '#tree' => TRUE,
      );

      $field_form += $this->editableFieldForm->form($field_form, $form_state);
      $form_id = $this->getFormId();
      \Drupal::moduleHandler()->alter(['form', 'editablefield_views_form'], $field_form, $form_state, $form_id);
      $field_form['actions']['submit']['#submit'][] = [$this, 'submitForm'];

      $form[$form_element_name][$form_element_row_id] = $field_form;
    }
    return $form;
  }

  /**
   * @inheritDoc
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
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
