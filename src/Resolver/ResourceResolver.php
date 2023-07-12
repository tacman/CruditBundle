<?php

declare(strict_types=1);

namespace Lle\CruditBundle\Resolver;

use Lle\CruditBundle\Contracts\CrudConfigInterface;
use Lle\CruditBundle\Contracts\DatasourceInterface;
use Lle\CruditBundle\Dto\Field\Field;
use Lle\CruditBundle\Dto\FieldView;
use Lle\CruditBundle\Dto\ResourceView;
use Symfony\Bundle\SecurityBundle\Security;

class ResourceResolver
{
    private FieldResolver $fieldResolver;

    private Security $security;

    public function __construct(
        FieldResolver $fieldResolver,
        Security $security
    ) {
        $this->fieldResolver = $fieldResolver;
        $this->security = $security;
    }

    /**
     * @param Field[] $fields
     */
    public function resolve(
        object $resource,
        array $fields,
        DatasourceInterface $datasource,
        ?CrudConfigInterface $crudConfig = null
    ): ResourceView {
        return new ResourceView(
            $datasource->getIdentifier($resource),
            $resource,
            $this->getFieldViews($fields, $resource, $datasource, $crudConfig)
        );
    }

    /**
     * @return FieldView[]
     */
    private function getFieldViews(
        array $fields,
        object $resource,
        DatasourceInterface $datasource,
        ?CrudConfigInterface $crudConfig = null
    ): array {
        $fieldViews = [];
        foreach ($fields as $key => $field) {
            if (is_array($field)) {
                foreach ($field as $cardField) {
                    if ($cardField->getRole() == null || $this->security->isGranted($cardField->getRole())) {
                        $fieldViews[$key][] = $this->fieldResolver->resolveView(
                            $cardField,
                            $resource,
                            $datasource,
                            $crudConfig
                        );
                    }
                }
            } else {
                if ($field->getRole() == null || $this->security->isGranted($field->getRole())) {
                    $fieldViews[] = $this->fieldResolver->resolveView($field, $resource, $datasource, $crudConfig);
                }
            }
        }

        return $fieldViews;
    }
}
