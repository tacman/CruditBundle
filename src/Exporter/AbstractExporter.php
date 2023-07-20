<?php

namespace Lle\CruditBundle\Exporter;

use Lle\CruditBundle\Contracts\ExporterInterface;
use Lle\CruditBundle\Dto\FieldView;
use Lle\CruditBundle\Field\DoctrineEntityField;

abstract class AbstractExporter implements ExporterInterface
{
    protected function getValue(FieldView $field): string
    {
        if ($field->getField()->getType() == DoctrineEntityField::class) {
            $field->getField()->setType("string");
        }

        if ($field->getField()->getTemplate()) {
            $result = $field->getValue();
        } else {
            switch ($field->getField()->getType()) {
                case "date":
                case "datetime":
                    $format = $field->getOptions()["format"];
                    if ($field->getRawValue()) {
                        $result = $field->getRawValue()->format($format);
                    } else {
                        $result = "";
                    }
                    break;
                case "decimal":
                case DoctrineEntityField::class:
                    $result = $field->getValue();
                    break;
                default:
                    $result = $field->getRawValue();
            }
        }

        return trim((string)$result);
    }
}
