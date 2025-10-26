<?php

namespace Sunnysideup\LLMIntegration\Traits;

trait EditModelTrait
{
    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }

    public function canDelete($member = null): bool
    {
        return false;
    }

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        foreach ($this->getReadonlyFields() as $fieldName) {
            $field = $fields->dataFieldByName($fieldName);
            if ($field) {
                $field->setReadonly(true);
            }
        }
        return $fields;
    }
}
