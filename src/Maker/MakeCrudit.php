<?php

declare(strict_types=1);

namespace Lle\CruditBundle\Maker;

use Doctrine\Common\Annotations\Annotation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Lle\CruditBundle\Datasource\AbstractDoctrineDatasource;
use Lle\CruditBundle\Dto\Field\Field;
use Lle\CruditBundle\Filter\FilterType\BooleanFilterType;
use Lle\CruditBundle\Filter\FilterType\DateFilterType;
use Lle\CruditBundle\Filter\FilterType\DateTimeFilterType;
use Lle\CruditBundle\Filter\FilterType\EntityFilterType;
use Lle\CruditBundle\Filter\FilterType\NumberFilterType;
use Lle\CruditBundle\Filter\FilterType\StringFilterType;
use Symfony\Bundle\MakerBundle\ConsoleStyle;
use Symfony\Bundle\MakerBundle\DependencyBuilder;
use Symfony\Bundle\MakerBundle\Doctrine\DoctrineHelper;
use Symfony\Bundle\MakerBundle\FileManager;
use Symfony\Bundle\MakerBundle\Generator;
use Symfony\Bundle\MakerBundle\InputConfiguration;
use Symfony\Bundle\MakerBundle\Maker\AbstractMaker;
use Symfony\Bundle\MakerBundle\Str;
use Symfony\Bundle\MakerBundle\Validator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\KernelInterface;

final class MakeCrudit extends AbstractMaker
{
    /** @var FileManager */
    private $fileManager;

    /** @var DoctrineHelper */
    private $entityHelper;

    /** @var bool */
    private $withController;

    /** @var string */
    private $projectDir;

    public function __construct(
        FileManager $fileManager,
        DoctrineHelper $entityHelper,
        KernelInterface $kernel
    )
    {
        $this->fileManager = $fileManager;
        $this->entityHelper = $entityHelper;
        $this->projectDir = $kernel->getProjectDir();
    }

    public static function getCommandName(): string
    {
        return 'make:crudit';
    }

    public function configureCommand(Command $command, InputConfiguration $inputConfig): void
    {
        $command
            ->setDescription('Creates a new controller class')
            ->addArgument(
                'entity-class',
                InputArgument::OPTIONAL,
                sprintf(
                    'The class name of the entity to create CRUD (e.g. <fg=yellow>%s</>)',
                    Str::asClassName(Str::getRandomTerm())
                )
            )
            ->addArgument(
                'namespace-controller',
                InputArgument::OPTIONAL,
                sprintf('Namespace for controller App/Controller/[...]/MyController.php ?')
            )
            ->addArgument(
                'filter',
                InputArgument::OPTIONAL,
                sprintf('Do you want some filters ?')
            )
            ->setHelp((string)file_get_contents(__DIR__ . '/../Resources/help/make_crudit.txt'));

        $inputConfig->setArgumentAsNonInteractive('entity-class');
        $inputConfig->setArgumentAsNonInteractive('namespace-controller');
        $inputConfig->setArgumentAsNonInteractive('filter');
    }

    public function interact(InputInterface $input, ConsoleStyle $io, Command $command): void
    {
        if (null === $input->getArgument('entity-class')) {
            $argument = $command->getDefinition()->getArgument('entity-class');
            $entities = $this->entityHelper->getEntitiesForAutocomplete();
            $question = new Question($argument->getDescription());
            $question->setAutocompleterValues($entities);
            $value = $io->askQuestion($question);
            $input->setArgument('entity-class', $value);
        }

        if (null === $input->getArgument('namespace-controller')) {
            $argument = $command->getDefinition()->getArgument('namespace-controller');
            $question = new Question($argument->getDescription(), 'Crudit');
            $finder = new Finder();
            $finder->in($this->projectDir . '/src/Controller/');
            $controllerNamespaces = [null];
            foreach ($finder->directories() as $dir) {
                /* @var SplFileInfo $dir */
                $controllerNamespaces[] = $dir->getBasename();
            }
            $question->setAutocompleterValues($controllerNamespaces);
            $value = $io->askQuestion($question);
            $input->setArgument('namespace-controller', $value);
        }

        if (null === $input->getArgument('filter')) {
            $argument = $command->getDefinition()->getArgument('filter');
            $question = new ConfirmationQuestion($argument->getDescription(), true);
            $value = $io->askQuestion($question);
            $input->setArgument('filter', $value);
        }
    }

    public function generate(InputInterface $input, ConsoleStyle $io, Generator $generator): void
    {
        $classname = Validator::entityExists(
            $this->getStringArgument('entity-class', $input),
            $this->entityHelper->getEntitiesForAutocomplete()
        );
        if (strpos($classname, '\\') === false) {
            $classname = "App\\Entity\\" . $classname;
        }
        $io->text('Create a configurator for ' . $classname);
        try {
            $this->createConfigurator($input, $io, $generator, $classname);
        } catch (\Exception $e) {
            $io->error($e->getMessage());
        }

        try {
            $this->createFormType($input, $io, $generator, $classname);
        } catch (\Exception $e) {
            $io->error($e->getMessage());
        }

        if ($this->getBoolArgument('filter', $input)) {
            try {
                $this->createFilterset($input, $io, $generator, $classname);
            } catch (\Exception $e) {
                $io->error($e->getMessage());
            }
        }

        try {
            $this->createDatasource($input, $io, $generator, $classname);
        } catch (\Exception $e) {
            $io->error($e->getMessage());
        }

        try {
            $this->createController($input, $io, $generator);
        } catch (\Exception $e) {
            $io->error($e->getMessage());
        }
    }

    private function getStringArgument(string $name, InputInterface $input): string
    {
        if (is_string($input->getArgument($name)) || is_null($input->getArgument($name))) {
            return (string)$input->getArgument($name);
        }
        throw new InvalidArgumentException($name . ' must be string type');
    }

    private function createConfigurator(
        InputInterface $input,
        ConsoleStyle $io,
        Generator $generator,
        string $entityClass
    ): string
    {
        $fields = $this->getFields($entityClass);
        $cruds = [
            'CrudConfigInterface::INDEX' => $fields,
            'CrudConfigInterface::SHOW' => $fields,
        ];

        $shortEntity = $this->getBasename($entityClass);

        $configuratorClassNameDetails = $generator->createClassNameDetails(
            $shortEntity,
            'Crudit\\Config\\',
            'CrudConfig'
        );

        $generator->generateClass(
            $configuratorClassNameDetails->getFullName(),
            $this->getSkeletonTemplate('config/CrudAutoConfig.php'),
            [
                'namespace' => 'App',
                'fields' => $fields,
                'cruds' => $cruds,
                'entityClass' => $shortEntity,
                'fullEntityClass' => $entityClass,
                'strictType' => true,
                'controllerRoute' => ($input->getArgument('namespace-controller')) ?
                    $this->getStringArgument('namespace-controller', $input) . '_' .
                    $shortEntity :
                    $shortEntity,
                'tabs' => [],
            ]
        );
        $generator->writeChanges();
        $this->writeSuccessMessage($io);

        return $configuratorClassNameDetails->getFullName();
    }

    private function getFields(string $entityClass): array
    {
        $fields = [];

        $metadata = $this->entityHelper->getMetadata($entityClass);
        if ($metadata instanceof ClassMetadata) {
            foreach ($metadata->getFieldNames() as $fieldname) {
                if ($fieldname === "id") {
                    continue;
                }

                $fields[] = Field::new($fieldname);
            }

            foreach ($metadata->getAssociationNames() as $fieldassoc) {
                if ($metadata->getAssociationMapping($fieldassoc)['type'] & ClassMetadataInfo::TO_ONE) {
                    $sortable = true;
                } else {
                    $sortable = false;
                }

                $fields[] = Field::new($fieldassoc)->setSortable($sortable);
            }
        }

        return $fields;
    }

    private function getFilters(string $entityClass): array
    {
        $filters = [];

        $metadata = $this->entityHelper->getMetadata($entityClass);
        if ($metadata instanceof ClassMetadata) {
            foreach ($metadata->getFieldNames() as $fieldname) {
                if ($fieldname === "id") {
                    continue;
                }

                $filters[] = $this->getFilterType($metadata, $fieldname);
            }

            foreach ($metadata->getAssociationNames() as $fieldassoc) {
                $filters[] = $this->getFilterType($metadata, $fieldassoc);
            }
        }

        return $filters;
    }

    public function getFilterType(ClassMetadataInfo $metadata, string $property): array
    {
        $fields = explode(".", $property);
        $multiLevelProperty = str_replace(".", ":", $property);

        if (count($fields) > 1) {
            // multi level
            for ($i = 0; $i < count($fields); $i++) {
                $field = $fields[$i];

                if ($i === count($fields) - 1) {
                    // it's the property
                    $property = $field;
                    break;
                }

                $association = $metadata->getAssociationMapping($field);
                $metadata = $this->entityHelper->getMetadata($association["targetEntity"]);
            }
        }

        if ($metadata->hasAssociation($property)) {
            $mapping = $metadata->getAssociationMapping($property);

            return [
                "type" => "EntityFilterType",
                "property" => $multiLevelProperty,
                "options" => [$this->getBasename($mapping["targetEntity"]) . "::class"],
                "uses" => [EntityFilterType::class, $mapping["targetEntity"]],
            ];
        }

        $type = $metadata->getTypeOfField($property);
        switch ($type) {
            case Types::BOOLEAN:
                return [
                    "type" => "BooleanFilterType",
                    "property" => $multiLevelProperty,
                    "options" => [],
                    "uses" => [BooleanFilterType::class],
                ];
            case Types::DATETIME_MUTABLE:
            case Types::DATETIMETZ_MUTABLE:
            case Types::DATETIME_IMMUTABLE:
            case Types::DATETIMETZ_IMMUTABLE:
                return [
                    "type" => "DateTimeFilterType",
                    "property" => $multiLevelProperty,
                    "options" => [],
                    "uses" => [DateTimeFilterType::class],
                ];
            case Types::DATE_IMMUTABLE:
            case Types::DATE_MUTABLE:
                return [
                    "type" => "DateFilterType",
                    "property" => $multiLevelProperty,
                    "options" => [],
                    "uses" => [DateFilterType::class],
                ];
            case Types::FLOAT:
            case Types::INTEGER:
            case Types::BIGINT:
            case Types::SMALLINT:
                return [
                    "type" => "NumberFilterType",
                    "property" => $multiLevelProperty,
                    "options" => [],
                    "uses" => [NumberFilterType::class],
                ];
            default:
                return [
                    "type" => "StringFilterType",
                    "property" => $multiLevelProperty,
                    "options" => [],
                    "uses" => [StringFilterType::class],
                ];
        }
    }

    public function getSkeletonTemplate(string $templateName): string
    {
        return __DIR__ . '/../Resources/skeleton/crud/' . $templateName;
    }

    private function createFormType(
        InputInterface $input,
        ConsoleStyle $io,
        Generator $generator,
        string $entityClass
    ): void
    {
        $fields = $this->getFields($entityClass);
        $shortEntity = $this->getBasename($entityClass);

        $formTypeClassNameDetails = $generator->createClassNameDetails(
            $shortEntity,
            'Form\\',
            'Type'
        );
        $generator->generateClass(
            $formTypeClassNameDetails->getFullName(),
            $this->getSkeletonTemplate('form/EntityCruditType.php'),
            [
                'namespace' => 'App',
                'entityClass' => $shortEntity,
                'fullEntityClass' => $input->getArgument('entity-class'),
                'fields' => $fields,
                'strictType' => true
            ]
        );
        $generator->writeChanges();
        $this->writeSuccessMessage($io);
    }

    private function getBoolArgument(string $name, InputInterface $input): bool
    {
        if (is_string($input->getArgument($name)) || is_bool($input->getArgument($name))) {
            return (bool)$input->getArgument($name);
        }
        throw new InvalidArgumentException($name . ' must be bool type');
    }

    private function createFilterset(
        InputInterface $input,
        ConsoleStyle $io,
        Generator $generator,
        string $entityClass
    ): void
    {
        $shortEntity = $this->getBasename($entityClass);

        $filters = $this->getFilters($entityClass);

        $uses = [];
        foreach ($filters as $filter) {
            array_push($uses, ...$filter["uses"]);
        }
        $uses = array_unique($uses);
        sort($uses);

        $filtersetClassNameDetails = $generator->createClassNameDetails(
            $shortEntity,
            'Crudit\Datasource\Filterset\\',
            'FilterSet'
        );
        $generator->generateClass(
            $filtersetClassNameDetails->getFullName(),
            $this->getSkeletonTemplate('filterset/EntityFilterset.php'),
            [
                'namespace' => 'App',
                'entityClass' => $shortEntity,
                'fullEntityClass' => $input->getArgument('entity-class'),
                'filters' => $filters,
                'strictType' => true,
                'uses' => $uses,
            ]
        );
        $generator->writeChanges();
        $this->writeSuccessMessage($io);
    }

    private function createDatasource(InputInterface $input, ConsoleStyle $io, Generator $generator, string $entityClass): void
    {
        if (count(AbstractDoctrineDatasource::getInitSearchFields($entityClass)) == 0) {
            $io->warning("You must set the searchFields property for autocompletion.");
        }
        $shortEntity = $this->getBasename($this->getStringArgument('entity-class', $input));

        $datasourceClassNameDetails = $generator->createClassNameDetails(
            $shortEntity,
            'Crudit\\Datasource\\',
            'Datasource'
        );
        $generator->generateClass(
            $datasourceClassNameDetails->getFullName(),
            $this->getSkeletonTemplate('datasource/DoctrineDatasource.php'),
            [
                'namespace' => 'App',
                'entityClass' => $shortEntity,
                'hasFilterset' => $this->getBoolArgument('filter', $input),
                'fullEntityClass' => $this->getStringArgument('entity-class', $input),
                'strictType' => true,
            ]
        );
        $generator->writeChanges();
        $this->writeSuccessMessage($io);
    }

    private function createController(InputInterface $input, ConsoleStyle $io, Generator $generator): string
    {
        $shortEntity = $this->getBasename($this->getStringArgument('entity-class', $input));

        $controllerClassNameDetails = $generator->createClassNameDetails(
            $shortEntity,
            $this->getStringArgument('namespace-controller', $input) ?
                'Controller\\' . $this->getStringArgument('namespace-controller', $input) . '\\' :
                'Controller\\',
            'Controller'
        );
        $generator->generateClass(
            $controllerClassNameDetails->getFullName(),
            $this->getSkeletonTemplate('controller/CrudController.php'),
            [
                'namespace' => 'App',
                'fullEntityClass' => $this->getStringArgument('entity-class', $input),
                'entityClass' => $shortEntity,
                'strictType' => true
            ]
        );
        $generator->writeChanges();
        $this->writeSuccessMessage($io);

        return $controllerClassNameDetails->getFullName();
    }

    public function configureDependencies(DependencyBuilder $dependencies): void
    {
        $dependencies->addClassDependency(
            Annotation::class,
            'annotations'
        );
    }

    private function getPathOfClass(string $class): string
    {
        if (class_exists($class)) {
            return (string)(new \ReflectionClass($class))->getFileName();
        }
        throw new InvalidArgumentException('entity class ' . $class . ' not found');
    }

    private function getBasename(string $class): string
    {
        return basename(str_replace('\\', '/', $class));
    }
}
