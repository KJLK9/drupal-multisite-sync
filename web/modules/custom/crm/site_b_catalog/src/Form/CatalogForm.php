<?php

declare(strict_types=1);

namespace Drupal\site_b_catalog\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form to add or edit a catalog entity.
 */
final class CatalogForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $entity = $this->getEntity();
    $args = ['%type' => $entity->getEntityType()->getSingularLabel(), '%label' => (string) $entity->label()];
    $this->messenger()->addStatus($result === SAVED_NEW
      ? $this->t('The %type %label has been created.', $args)
      : $this->t('The %type %label has been updated.', $args));
    $form_state->setRedirectUrl($entity->toUrl('collection'));
    return $result;
  }

}
