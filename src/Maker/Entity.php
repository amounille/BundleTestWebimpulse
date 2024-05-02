<?php

namespace WebImpulse\SiteBundle\Maker;

use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Symfony\Bundle\MakerBundle\ConsoleStyle;
use Symfony\Bundle\MakerBundle\DependencyBuilder;
use Symfony\Bundle\MakerBundle\Doctrine\DoctrineHelper;
use Symfony\Bundle\MakerBundle\Doctrine\EntityClassGenerator;
use Symfony\Bundle\MakerBundle\Doctrine\EntityRelation;
use Symfony\Bundle\MakerBundle\Generator;
use Symfony\Bundle\MakerBundle\InputConfiguration;
use Symfony\Bundle\MakerBundle\Maker\AbstractMaker;
use Symfony\Bundle\MakerBundle\Maker\Common\EntityIdTypeEnum;
use Symfony\Bundle\MakerBundle\MakerInterface;
use Symfony\Bundle\MakerBundle\Str;
use Symfony\Bundle\MakerBundle\Util\ClassDetails;
use Symfony\Bundle\MakerBundle\Util\ClassSource\Model\ClassProperty;
use Symfony\Bundle\MakerBundle\Util\ClassSourceManipulator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Yaml\Yaml;
use WebImpulse\SiteBundle\Service\TypeNamerService;


/**
 * @method string getCommandDescription()
 */
final class Entity extends AbstractMaker
{
    private array $types = [
        'ManyToOne',
        'OneToMany',
        'ManyToMany',
        'OneToOne'
    ];

    private array $entiteType = [];

    private array $personalizedFields = [
        'image'
    ];

    private TypeNamerService $typeNamerService;

    /**
     * @param TypeNamerService $typeNamerService
     */
    public function __construct()
    {
        $this->typeNamerService = new TypeNamerService();
    }

    public static function getCommandName(): string
    {
        return "make:site:entity";
    }

    public function configureCommand(Command $command, InputConfiguration $inputConfig)
    {
        $command
            ->setDescription("Ajout d'une entité pour un site web")
            ->addArgument('nom', InputArgument::REQUIRED, "Nom de l'entité");
    }

    public function configureDependencies(DependencyBuilder $dependencies, InputInterface $input = null)
    {

    }

    public function generate(InputInterface $input, ConsoleStyle $io, Generator $generator)
    {
        $entityClassDetails = $generator->createClassNameDetails(
            $input->getArgument('nom'),
            'Entity\\'
        );

        $classExists = class_exists($entityClassDetails->getFullName());

        $path = $input->getArgument('nom');
        $sections = explode("\\", $path);
        $class = end($sections);

        if(!$classExists) {
            $entityPath = $generator->generateClass($entityClassDetails->getFullName() , 'doctrine/Entity.tpl.php', [
                'use_statements' => "
use Doctrine\ORM\Mapping as ORM;
use App\\Repository\\" . $path . "Repository;
            ",
                'repository_class_name' => $class . "Repository",
                'should_escape_table_name' => false,
                'api_resource' => false,
                'broadcast' => false,
                'id_type' => EntityIdTypeEnum::UUID,
            ]);

            $generator->generateClass('App\\Repository\\' . $path . "Repository", 'doctrine/Repository.tpl.php', [
                'use_statements' => "
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use App\\Entity\\".$path.";
            ",
                'entity_class_name' => $class . "Repository",
                'with_password_upgrade' => false,
                'include_example_comments' => true,
                'entity_alias' => 'a'
            ]);

            $generator->writeChanges();
        }

        if ($classExists) {
            $entityPath = $this->getPathOfClass($entityClassDetails->getFullName());
            $io->text([
                'Your entity already exists! So let\'s add some new fields!',
            ]);
        } else {
            $io->text([
                '',
                'Entity generated! Now let\'s add some fields!',
                'You can always add more fields later manually or by re-running this command.',
            ]);
        }

        $stofPathConfig = $generator->getRootDirectory() . "/config/packages/stof_doctrine_extensions.yaml";
        if(file_exists($stofPathConfig)) {
            $data = Yaml::parseFile($stofPathConfig);
            if(isset($data["stof_doctrine_extensions"]["orm"]["default"])) {
                foreach ($data["stof_doctrine_extensions"]["orm"]["default"] as $nom => $extension) {
                    if($extension) {
                        $this->entiteType[] = $nom;
                    }
                }
            }
        }

        $currentFields = $this->getPropertyNames($entityClassDetails->getFullName());
        $fields = [];

        $isFirstField = true;
        while (true) {
            $manipulator = $this->createClassManipulator($entityPath, $io, false);
            $newField = $this->askForNextField($io, $currentFields, $entityClassDetails->getFullName(), $isFirstField);
            $isFirstField = false;

            if (null === $newField) {
                break;
            }

            $fileManagerOperations = [];
            $fileManagerOperations[$entityPath] = $manipulator;

            if ($newField instanceof ClassProperty) {
                $annotationOptions = $newField->options;

                if($newField["type"] == "image") {
                    $newField->options = ["type" => "string"];
                }
                $manipulator->addEntityField($newField);

                if($newField->type == "image") {
                    $fileManagerOperations[$entityPath] = $this->typeNamerService->imageType($generator, $io, $entityPath, $manipulator, $class, $newField);
                }

                $currentFields[] = $newField->propertyName;
                $fields[] = $newField;
            } elseif ($newField instanceof EntityRelation) {
                // both overridden below for OneToMany
                $newFieldName = $newField->getOwningProperty();
                if ($newField->isSelfReferencing()) {
                    $otherManipulatorFilename = $entityPath;
                    $otherManipulator = $manipulator;
                } else {
                    $otherManipulatorFilename = $this->getPathOfClass($newField->getInverseClass());
                    $otherManipulator = $this->createClassManipulator($otherManipulatorFilename, $io, true);
                }
                switch ($newField->getType()) {
                    case 'ManyToOne':
                        if ($newField->getOwningClass() === $entityClassDetails->getFullName()) {
                            // THIS class will receive the ManyToOne
                            $manipulator->addManyToOneRelation($newField->getOwningRelation());

                            if ($newField->getMapInverseRelation()) {
                                $otherManipulator->addOneToManyRelation($newField->getInverseRelation());
                            }
                        } else {
                            // the new field being added to THIS entity is the inverse
                            $newFieldName = $newField->getInverseProperty();
                            $otherManipulatorFilename = $this->getPathOfClass($newField->getOwningClass());
                            $otherManipulator = $this->createClassManipulator($otherManipulatorFilename, $io, true);

                            // The *other* class will receive the ManyToOne
                            $otherManipulator->addManyToOneRelation($newField->getOwningRelation());
                            if (!$newField->getMapInverseRelation()) {
                                throw new \Exception('Somehow a OneToMany relationship is being created, but the inverse side will not be mapped?');
                            }
                            $manipulator->addOneToManyRelation($newField->getInverseRelation());
                        }

                        break;
                    case 'ManyToMany':
                        $manipulator->addManyToManyRelation($newField->getOwningRelation());
                        if ($newField->getMapInverseRelation()) {
                            $otherManipulator->addManyToManyRelation($newField->getInverseRelation());
                        }

                        break;
                    case 'OneToOne':
                        $manipulator->addOneToOneRelation($newField->getOwningRelation());
                        if ($newField->getMapInverseRelation()) {
                            $otherManipulator->addOneToOneRelation($newField->getInverseRelation());
                        }

                        break;
                    default:
                        throw new \Exception('Invalid relation type');
                }

                // save the inverse side if it's being mapped
                if ($newField->getMapInverseRelation()) {
                    $fileManagerOperations[$otherManipulatorFilename] = $otherManipulator;
                }
                $currentFields[] = $newFieldName;
                $fields[] = $newField;
            } else {
                throw new \Exception('Invalid value');
            }

            foreach ($fileManagerOperations as $path => $manipulatorOrMessage) {
                if (\is_string($manipulatorOrMessage)) {
                    $io->comment($manipulatorOrMessage);
                } else {
                    $generator->dumpFile($path, $manipulatorOrMessage->getSourceCode());
                }
            }

            $generator->writeChanges();

            //$this->generateController($fields, $generator);
        }

        $type = true;
        while($type != null && $input->getArgument('nom') != "") {
            $typeString = "";
            foreach ($this->entiteType as $key => $entiteType) {
                if($key != array_key_first($this->entiteType)) {
                    $typeString .= " ";
                }
                $typeString .= $entiteType . "\n";
            }
            $question = new Question("Entité type ? \n $typeString");
            $question->setAutocompleterValues($this->entiteType);
            $type = $io->askQuestion($question);
            $manipulator = $this->createClassManipulator($entityPath, $io, false);
            if($type == "timestampable") {
                $fileManagerOperations[$entityPath] = $this->typeNamerService->timestampableType($generator, $io, $entityPath, $manipulator, $class, $entityClassDetails->getFullName(), $generator->getRootDirectory());
            }
            if($type == "blameable") {
                $fileManagerOperations[$entityPath] = $this->typeNamerService->blameableType($generator, $io, $entityPath, $manipulator, $class, $entityClassDetails->getFullName(), $generator->getRootDirectory());
            }
            if($type == "sluggable") {
                $fileManagerOperations[$entityPath] = $this->typeNamerService->sluggableType($generator, $io, $entityPath, $manipulator, $class, $entityClassDetails->getFullName(), $generator->getRootDirectory());
            }
        }

        $generator->writeChanges();
    }

    public function __call(string $name, array $arguments)
    {

    }

    private function askForNextField(ClassProperty $mapping)
    {
        $typeHint = DoctrineHelper::getPropertyTypeForColumn($mapping->type);
        if ($typeHint && DoctrineHelper::canColumnTypeBeInferredByPropertyType($mapping->type, $typeHint)) {
            $mapping->needsTypeHint = false;
        }

        if ($mapping->needsTypeHint) {
            $typeConstant = DoctrineHelper::getTypeConstant($mapping->type);
            if ($typeConstant) {
                $this->addUseStatementIfNecessary(Types::class);
                $mapping->type = $typeConstant;
            }
        }

        // 2) USE property type on property below, nullable
        // 3) If default value, then NOT nullable

        $nullable = $mapping->nullable ?? false;

        $attributes[] = $this->buildAttributeNode(Column::class, $mapping->getAttributes(), 'ORM');

        $defaultValue = null;
        if ('array' === $typeHint && !$nullable) {
            $defaultValue = "";
        } elseif ($typeHint && '\\' === $typeHint[0] && false !== strpos($typeHint, '\\', 1)) {
            $typeHint = $this->addUseStatementIfNecessary(substr($typeHint, 1));
        }

        $propertyType = $typeHint;
        if ($propertyType && !$defaultValue) {
            // all property types
            $propertyType = '?'.$propertyType;
        }

        $this->addProperty(
            name: $mapping->propertyName,
            defaultValue: $defaultValue,
            attributes: $attributes,
            comments: $mapping->comments,
            propertyType: $propertyType
        );

        $this->addGetter(
            $mapping->propertyName,
            $typeHint,
            // getter methods always have nullable return values
            // because even though these are required in the db, they may not be set yet
            // unless there is a default value
            null === $defaultValue
        );

        // don't generate setters for id fields
        if (!($mapping->id ?? false)) {
            $this->addSetter($mapping->propertyName, $typeHint, $nullable);
        }
    }

    private function getPropertyNames(string $class): array
    {
        if (!class_exists($class)) {
            return [];
        }

        $reflClass = new \ReflectionClass($class);

        return array_map(static fn (\ReflectionProperty $prop) => $prop->getName(), $reflClass->getProperties());
    }

    private function printAvailableTypes(ConsoleStyle $io): void
    {
        $allTypes = Type::getTypesMap();

        if ('Hyper' === getenv('TERM_PROGRAM')) {
            $wizard = 'wizard 🧙';
        } else {
            $wizard = '\\' === \DIRECTORY_SEPARATOR ? 'wizard' : 'wizard 🧙';
        }

        $main = [
            'string' => [],
            'text' => [],
            'boolean' => [],
            'integer' => ['smallint', 'bigint'],
            'float' => [],
        ];

        foreach ($this->personalizedFields as $personalizedField) {
            $main[$personalizedField] = [];
        }

        $typesTable = [
            'main' => $main,
            'relation' => [
                'relation' => 'a '.$wizard.' will help you build the relation',
                'ManyToOne' => [],
                'OneToMany' => [],
                'ManyToMany' => [],
                'OneToOne' => [],
            ],
            'array_object' => [
                'array' => ['simple_array'],
                'json' => [],
                'object' => [],
                'binary' => [],
                'blob' => [],
            ],
            'date_time' => [
                'datetime' => ['datetime_immutable'],
                'datetimetz' => ['datetimetz_immutable'],
                'date' => ['date_immutable'],
                'time' => ['time_immutable'],
                'dateinterval' => [],
            ],
        ];

        $printSection = static function (array $sectionTypes) use ($io, &$allTypes) {
            foreach ($sectionTypes as $mainType => $subTypes) {
                unset($allTypes[$mainType]);
                $line = sprintf('  * <comment>%s</comment>', $mainType);

                if (\is_string($subTypes) && $subTypes) {
                    $line .= sprintf(' or %s', $subTypes);
                } elseif (\is_array($subTypes) && !empty($subTypes)) {
                    $line .= sprintf(' or %s', implode(' or ', array_map(
                            static fn ($subType) => sprintf('<comment>%s</comment>', $subType), $subTypes))
                    );

                    foreach ($subTypes as $subType) {
                        unset($allTypes[$subType]);
                    }
                }

                $io->writeln($line);
            }

            $io->writeln('');
        };

        $io->writeln('<info>Main Types</info>');
        $printSection($typesTable['main']);

        $io->writeln('<info>Relationships/Associations</info>');
        $printSection($typesTable['relation']);

        $io->writeln('<info>Array/Object Types</info>');
        $printSection($typesTable['array_object']);

        $io->writeln('<info>Date/Time Types</info>');
        $printSection($typesTable['date_time']);

        $io->writeln('<info>Other Types</info>');
        // empty the values
        $allTypes = array_map(static fn () => [], $allTypes);
        $printSection($allTypes);
    }

    /**
     * @throws \Exception
     */
    private function askRelationDetails(ConsoleStyle $io, string $generatedEntityClass, string $type, string $newFieldName)
    {
        // ask the targetEntity
        $targetEntityClass = null;
        while (null === $targetEntityClass) {
            $question = $this->createEntityClassQuestion('What class should this entity be related to?');

            $answeredEntityClass = $io->askQuestion($question);

            // find the correct class name - but give priority over looking
            // in the Entity namespace versus just checking the full class
            // name to avoid issues with classes like "Directory" that exist
            // in PHP's core.
            if (class_exists($this->getEntityNamespace().'\\'.$answeredEntityClass)) {
                $targetEntityClass = $this->getEntityNamespace().'\\'.$answeredEntityClass;
            } elseif (class_exists($answeredEntityClass)) {
                $targetEntityClass = $answeredEntityClass;
            } else {
                $io->error(sprintf('Unknown class "%s"', $answeredEntityClass));
                continue;
            }
        }

        // help the user select the type
        if ('relation' === $type) {
            $type = $this->askRelationType($io, $generatedEntityClass, $targetEntityClass);
        }

        $askFieldName = fn (string $targetClass, string $defaultValue) => $io->ask(
            sprintf('New field name inside %s', Str::getShortClassName($targetClass)),
            $defaultValue,
            function ($name) use ($targetClass) {
                // it's still *possible* to create duplicate properties - by
                // trying to generate the same property 2 times during the
                // same make:entity run. property_exists() only knows about
                // properties that *originally* existed on this class.
                if (property_exists($targetClass, $name)) {
                    throw new \InvalidArgumentException(sprintf('The "%s" class already has a "%s" property.', $targetClass, $name));
                }

                if (!Str::isValidPhpVariableName($name)) {
                    throw new \InvalidArgumentException(sprintf('"%s" is not a valid PHP property name.', $name));
                }

                return $name;
            }
        );

        $askIsNullable = static fn (string $propertyName, string $targetClass) => $io->confirm(sprintf(
            'Is the <comment>%s</comment>.<comment>%s</comment> property allowed to be null (nullable)?',
            Str::getShortClassName($targetClass),
            $propertyName
        ));

        $askOrphanRemoval = static function (string $owningClass, string $inverseClass) use ($io) {
            $io->text([
                'Do you want to activate <comment>orphanRemoval</comment> on your relationship?',
                sprintf(
                    'A <comment>%s</comment> is "orphaned" when it is removed from its related <comment>%s</comment>.',
                    Str::getShortClassName($owningClass),
                    Str::getShortClassName($inverseClass)
                ),
                sprintf(
                    'e.g. <comment>$%s->remove%s($%s)</comment>',
                    Str::asLowerCamelCase(Str::getShortClassName($inverseClass)),
                    Str::asCamelCase(Str::getShortClassName($owningClass)),
                    Str::asLowerCamelCase(Str::getShortClassName($owningClass))
                ),
                '',
                sprintf(
                    'NOTE: If a <comment>%s</comment> may *change* from one <comment>%s</comment> to another, answer "no".',
                    Str::getShortClassName($owningClass),
                    Str::getShortClassName($inverseClass)
                ),
            ]);

            return $io->confirm(sprintf('Do you want to automatically delete orphaned <comment>%s</comment> objects (orphanRemoval)?', $owningClass), false);
        };

        $askInverseSide = function ($relation) use ($io) {

            // recommend an inverse side, except for OneToOne, where it's inefficient
            $recommendMappingInverse = 'OneToOne' !== $relation->getType();

            $getterMethodName = 'get'.Str::asCamelCase(Str::getShortClassName($relation->getOwningClass()));
            if ('OneToOne' !== $relation->getType()) {
                // pluralize!
                $getterMethodName = Str::singularCamelCaseToPluralCamelCase($getterMethodName);
            }
            $mapInverse = $io->confirm(
                sprintf(
                    'Do you want to add a new property to <comment>%s</comment> so that you can access/update <comment>%s</comment> objects from it - e.g. <comment>$%s->%s()</comment>?',
                    Str::getShortClassName($relation->getInverseClass()),
                    Str::getShortClassName($relation->getOwningClass()),
                    Str::asLowerCamelCase(Str::getShortClassName($relation->getInverseClass())),
                    $getterMethodName
                ),
                $recommendMappingInverse
            );
            $relation->setMapInverseRelation($mapInverse);
        };

        switch ($type) {
            case 'ManyToOne':
                $relation = new EntityRelation(
                    'ManyToOne',
                    $generatedEntityClass,
                    $targetEntityClass
                );
                $relation->setOwningProperty($newFieldName);

                $relation->setIsNullable($askIsNullable(
                    $relation->getOwningProperty(),
                    $relation->getOwningClass()
                ));

                $askInverseSide($relation);
                if ($relation->getMapInverseRelation()) {
                    $io->comment(sprintf(
                        'A new property will also be added to the <comment>%s</comment> class so that you can access the related <comment>%s</comment> objects from it.',
                        Str::getShortClassName($relation->getInverseClass()),
                        Str::getShortClassName($relation->getOwningClass())
                    ));
                    $relation->setInverseProperty($askFieldName(
                        $relation->getInverseClass(),
                        Str::singularCamelCaseToPluralCamelCase(Str::getShortClassName($relation->getOwningClass()))
                    ));

                    // orphan removal only applies if the inverse relation is set
                    if (!$relation->isNullable()) {
                        $relation->setOrphanRemoval($askOrphanRemoval(
                            $relation->getOwningClass(),
                            $relation->getInverseClass()
                        ));
                    }
                }

                break;
            case 'OneToMany':
                // we *actually* create a ManyToOne, but populate it differently
                $relation = new EntityRelation(
                    'OneToMany',
                    $targetEntityClass,
                    $generatedEntityClass
                );
                $relation->setInverseProperty($newFieldName);

                $io->comment(sprintf(
                    'A new property will also be added to the <comment>%s</comment> class so that you can access and set the related <comment>%s</comment> object from it.',
                    Str::getShortClassName($relation->getOwningClass()),
                    Str::getShortClassName($relation->getInverseClass())
                ));
                $relation->setOwningProperty($askFieldName(
                    $relation->getOwningClass(),
                    Str::asLowerCamelCase(Str::getShortClassName($relation->getInverseClass()))
                ));

                $relation->setIsNullable($askIsNullable(
                    $relation->getOwningProperty(),
                    $relation->getOwningClass()
                ));

                if (!$relation->isNullable()) {
                    $relation->setOrphanRemoval($askOrphanRemoval(
                        $relation->getOwningClass(),
                        $relation->getInverseClass()
                    ));
                }

                break;
            case 'ManyToMany':
                $relation = new EntityRelation(
                    'ManyToMany',
                    $generatedEntityClass,
                    $targetEntityClass
                );
                $relation->setOwningProperty($newFieldName);

                $askInverseSide($relation);
                if ($relation->getMapInverseRelation()) {
                    $io->comment(sprintf(
                        'A new property will also be added to the <comment>%s</comment> class so that you can access the related <comment>%s</comment> objects from it.',
                        Str::getShortClassName($relation->getInverseClass()),
                        Str::getShortClassName($relation->getOwningClass())
                    ));
                    $relation->setInverseProperty($askFieldName(
                        $relation->getInverseClass(),
                        Str::singularCamelCaseToPluralCamelCase(Str::getShortClassName($relation->getOwningClass()))
                    ));
                }

                break;
            case 'OneToOne':
                $relation = new EntityRelation(
                    'OneToOne',
                    $generatedEntityClass,
                    $targetEntityClass
                );
                $relation->setOwningProperty($newFieldName);

                $relation->setIsNullable($askIsNullable(
                    $relation->getOwningProperty(),
                    $relation->getOwningClass()
                ));

                $askInverseSide($relation);
                if ($relation->getMapInverseRelation()) {
                    $io->comment(sprintf(
                        'A new property will also be added to the <comment>%s</comment> class so that you can access the related <comment>%s</comment> object from it.',
                        Str::getShortClassName($relation->getInverseClass()),
                        Str::getShortClassName($relation->getOwningClass())
                    ));
                    $relation->setInverseProperty($askFieldName(
                        $relation->getInverseClass(),
                        Str::asLowerCamelCase(Str::getShortClassName($relation->getOwningClass()))
                    ));
                }

                break;
            default:
                throw new \InvalidArgumentException('Invalid type: '.$type);
        }

        return $relation;
    }

    private function createEntityClassQuestion(string $questionText): Question
    {
        return new Question($questionText);
    }

    private function getEntityNamespace(): string
    {
        return "App\\Entity";
    }

    private function askRelationType(ConsoleStyle $io, string $entityClass, string $targetEntityClass)
    {
        $io->writeln('What type of relationship is this?');

        $originalEntityShort = Str::getShortClassName($entityClass);
        $targetEntityShort = Str::getShortClassName($targetEntityClass);
        $rows = [];
        $rows[] = [
            'ManyToOne',
            sprintf("Each <comment>%s</comment> relates to (has) <info>one</info> <comment>%s</comment>.\nEach <comment>%s</comment> can relate to (can have) <info>many</info> <comment>%s</comment> objects.", $originalEntityShort, $targetEntityShort, $targetEntityShort, $originalEntityShort),
        ];
        $rows[] = ['', ''];
        $rows[] = [
            'OneToMany',
            sprintf("Each <comment>%s</comment> can relate to (can have) <info>many</info> <comment>%s</comment> objects.\nEach <comment>%s</comment> relates to (has) <info>one</info> <comment>%s</comment>.", $originalEntityShort, $targetEntityShort, $targetEntityShort, $originalEntityShort),
        ];
        $rows[] = ['', ''];
        $rows[] = [
            'ManyToMany',
            sprintf("Each <comment>%s</comment> can relate to (can have) <info>many</info> <comment>%s</comment> objects.\nEach <comment>%s</comment> can also relate to (can also have) <info>many</info> <comment>%s</comment> objects.", $originalEntityShort, $targetEntityShort, $targetEntityShort, $originalEntityShort),
        ];
        $rows[] = ['', ''];
        $rows[] = [
            'OneToOne',
            sprintf("Each <comment>%s</comment> relates to (has) exactly <info>one</info> <comment>%s</comment>.\nEach <comment>%s</comment> also relates to (has) exactly <info>one</info> <comment>%s</comment>.", $originalEntityShort, $targetEntityShort, $targetEntityShort, $originalEntityShort),
        ];

        $io->table([
            'Type',
            'Description',
        ], $rows);

        $question = new Question(sprintf(
            'Relation type? [%s]',
            implode(', ', $this->types)
        ));
        $question->setAutocompleterValues($this->types);
        $question->setValidator(function ($type) {
            if (!\in_array($type, $this->types)) {
                throw new \InvalidArgumentException(sprintf('Invalid type: use one of: %s', implode(', ', $this->types)));
            }

            return $type;
        });

        return $io->askQuestion($question);
    }

    private function getPathOfClass(string $class): string
    {
        return (new ClassDetails($class))->getPath();
    }

    private function createClassManipulator($content, $io, $overwrite)
    {
        $manipulator = new ClassSourceManipulator(
            sourceCode: file_get_contents($content),
            overwrite: $overwrite,
        );

        $manipulator->setIo($io);

        return $manipulator;
    }

//    public function generateController(array $fields, Generator $generator) {
//        $content = file_get_contents($generator->getRootDirectory() . '/composer.json');
//        $bundle = json_decode($content, true);
//
//        $files = scandir($generator->getRootDirectory() . '/src/Controller');
//
//        if(isset($bundle["require"]["easycorp/easyadmin-bundle"])) {
//            $fileFound = $this->findFileInDirectory($files, "DashboardController.php");
//
//            if(!$fileFound) {
//
//            }
//        }
//    }
//
//    public function findFileInDirectory($files, $fileName) :bool
//    {
//        foreach ($files as $file) {
//            if(is_dir($file)) {
//                $this->findFileInDirectory($file, $fileName);
//            }
//            else if (strpos($fileName, $file) !== false) {
//                return true;
//            }
//        }
//        return false;
//    }
}
