<?php

namespace Lle\CruditBundle\Filter\FilterType;

use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Workflow\WorkflowInterface;

class WorkflowFilterType extends AbstractFilterType
{
    protected WorkflowInterface $workflow;

    public static function new(string $fieldname, WorkflowInterface $workflow): self
    {
        return (new self($fieldname))
            ->setWorkflow($workflow)
            ->setAdditionnalKeys(['items']);
    }

    public function apply(QueryBuilder $queryBuilder): void
    {
        if (!isset($this->data["op"])) {
            return;
        }

        $op = $this->data["op"];

        [$column, $alias, $paramname] = $this->getQueryParams($queryBuilder);

        $query = $this->getPattern($op, $column, $alias, $column, $paramname);

        if (in_array($op, ["isnull", "isnotnull"])) {
            $queryBuilder->andWhere($query);
        } elseif (
            isset($this->data["value"])
            && $this->data["value"]
        ) {
            $ids = explode(",", $this->data["value"]);

            $queryBuilder->setParameter($paramname, $ids);
            $queryBuilder->andWhere($query);
        }
    }

    private function getPattern($op, $id, $alias, $col, $paramname)
    {
        $pattern = null;
        switch ($op) {
            case "isnull":
                $pattern = $alias . $col . " IS NULL OR " . $alias . $col . " = '' ";
                break;
            case "isnotnull":
                $pattern = $alias . $col . " IS NOT NULL AND " . $alias . $col . " <> '' ";
                break;
            case "eq":
                $pattern = $alias . $col . " IN (:" . $paramname . ")";
                break;
            case "neq":
                $pattern = $alias . $col . " NOT IN (:" . $paramname . ")";
                break;
        }

        return $pattern ? "(" . $pattern . ")" : null;
    }

    public function getOperators(): array
    {
        return [
            "eq" => ["icon" => "fas fa-equals"],
            "neq" => ["icon" => "fas fa-not-equal"],
            "isnull" => ["icon" => "far fa-square"],
            "isnotnull" => ["icon" => "fas fa-square"],
        ];
    }

    public function getChoices(): array
    {
        $choices = [];

        foreach ($this->workflow->getDefinition()->getPlaces() as $place) {
            $choices[] = $place;
        }

        return $choices;
    }

    public function isSelected($data, $value)
    {
        if (is_array($data["value"])) {
            return in_array($value, $data["value"]);
        } else {
            return ($data["value"] === $value);
        }
    }

    public function getWorkflow(): WorkflowInterface
    {
        return $this->workflow;
    }

    public function setWorkflow(WorkflowInterface $workflow): self
    {
        $this->workflow = $workflow;

        return $this;
    }

    public function setData($data): void
    {
        parent::setData($data);

        $items = [];
        if (!isset($data['items']) && isset($data['value']) && $data['value'] !== '') {
            foreach (explode(',', $data['value']) as $value) {
                $items[] = ['id' => $value];
            }
        }

        $this->data["items"] = json_encode($items);
    }
}
