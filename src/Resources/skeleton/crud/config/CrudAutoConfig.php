<?= "<?php" ?>
<?php
if ($strictType): ?>


    declare(strict_types=1);
<?php
endif; ?>

namespace <?= $namespace ?>;

<?php
if (count($tabs)) { ?>
    use Lle\CruditBundle\Brick\HistoryBrick\HistoryConfig;
    use Lle\CruditBundle\Brick\SublistBrick\SublistConfig;
<?php
} ?>
use Lle\CruditBundle\Dto\Field\Field;
use Lle\CruditBundle\Crud\AbstractCrudConfig;
use Lle\CruditBundle\Contracts\CrudConfigInterface;
use App\Crudit\Datasource\<?= $prefixFilename ?>Datasource;

class <?= $prefixFilename ?>CrudConfig extends AbstractCrudConfig
{
public function __construct(
<?php
foreach ($tabs as $tab) { ?>
    <?php
    if ($tab["type"] === "sublist") { ?>
        private <?= $tab["linkedEntity"] ?>CrudConfig $<?= strtolower($tab["linkedEntity"]) ?>CrudConfig,
    <?php
    } ?>
<?php
} ?>
<?= $prefixFilename ?>Datasource $datasource
)
{
$this->datasource = $datasource;
}

/**
* @param string $key
* @return Field[]
*/
public function getFields($key): array
{
<?php
foreach ($fields as $field) { ?>
    <?php
    if (is_array($field)) { ?>
        <?php
        foreach ($field as $value) { ?>
            $<?php
            echo str_replace(".", "", $value->getName()) ?> = Field::new("<?php
            echo $value->getName() ?>");
        <?php
        } ?>
    <?php
    } else { ?>
        $<?php
        echo str_replace(".", "", $field->getName()) ?> = Field::new("<?php
        echo $field->getName() ?>");
    <?php
    } ?>
<?php
} ?>

switch ($key) {
<?php
foreach ($cruds as $crud => $crudFields) { ?>
    case <?= $crud ?>:
    $fields = [
    <?php
    foreach ($crudFields as $key => $field) { ?>
        <?php
        if (is_array($field)) { ?>
            "<?= $key ?>" => [
            <?php
            foreach ($field as $value) { ?>
                $<?= str_replace(".", "", $value->getName()) ?><?php
                if (!$value->isSortable()) {
                    echo "->setSortable(false)";
                } ?>,
            <?php
            } ?>
            ],
        <?php
        } else { ?>
            $<?= str_replace(".", "", $field->getName()) ?><?php
            if (!$field->isSortable()) {
                echo "->setSortable(false)";
            } ?>,
        <?php
        } ?>
    <?php
    } ?>
    ];
    break;
<?php
} ?>
default:
$fields = [];
}

return $fields;
}
<?php
if (isset($forms) && count($forms)) { ?>

    protected function getFormType(string $pageKey): ?string
    {
    switch ($pageKey) {
    <?php
    foreach ($forms as $key => $formPrefix) { ?>
        case <?= $key ?>:
        $prefix = "<?= $formPrefix ?>";
        break;
    <?php
    } ?>
    default:
    return null;
    }

    return str_replace(
    "App\\Crudit\\Config\\",
    "App\\Form\\" . $prefix,
    str_replace("CrudConfig", "Type", get_class($this))
    );
    }
<?php
} ?>
<?php
if (isset($tabs) && count($tabs)) { ?>

    public function getTabs(): array
    {
    return [
    <?php
    foreach ($tabs as $tab) { ?>
        <?php
        if ($tab["type"] === "history") { ?>
            "<?= $tab["label"] ?>" => [HistoryConfig::new()],
        <?php
        } elseif ($tab["type"] === "sublist") { ?>
            "<?= $tab["label"] ?>" => [SublistConfig::new("<?= $tab["property"] ?>", $this-><?= strtolower(
                $tab["linkedEntity"]
            ) ?>CrudConfig)
            ->setFields($this-><?= strtolower(
                $tab["linkedEntity"]
            ) ?>CrudConfig->getFields(CrudConfigInterface::INDEX))],
        <?php
        } ?>
    <?php
    } ?>
    ];
    }
<?php
} ?>
<?php
if (isset($sort["property"]) && isset($sort["order"])) { ?>

    public function getDefaultSort(): array
    {
    return [["<?= $sort["property"] ?>", "<?= $sort["order"] ?>"]];
    }
<?php
} ?>

public function getRootRoute(): string
{
return "app_<?= strtolower($controllerRoute) ?>";
}
}
