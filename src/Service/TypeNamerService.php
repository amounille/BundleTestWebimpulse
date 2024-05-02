<?php

namespace WebImpulse\SiteBundle\Service;

use Doctrine\DBAL\Exception;
use Symfony\Bundle\MakerBundle\ConsoleStyle;
use Symfony\Bundle\MakerBundle\Generator;
use Symfony\Bundle\MakerBundle\Util\ClassSource\Model\ClassProperty;
use Symfony\Bundle\MakerBundle\Util\ClassSourceManipulator;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Yaml\Yaml;
use WebImpulse\SiteBundle\Maker\Installation;
use function Composer\Autoload\includeFile;

class TypeNamerService
{
    public function imageType(Generator $generator,ConsoleStyle $io, $entityPath, $manipulator, $class, ClassProperty $newField) {
        /**
         * Entités
         */

        $content = file_get_contents($generator->getRootDirectory() . '/composer.json');
        $bundle = json_decode($content, true);

        if(!isset($bundle["require"]["vich/uploader-bundle"])) {
            $question = new Question("Version de Vich Uploader (numéro de version ou last pour installer la plus récente)");
            $version = $io->askQuestion($question);
            if(preg_match("/^[0-9]{1,}\.[0-9]{1,}\.?[0-9]{1,}$/", $version) || $version == "last") {
                if($version == "last") {
                    $command = "composer require vich/uploader-bundle";
                }
                else {
                    $command = "composer require vich/uploader-bundle:" . $version;
                }
                exec($command);
                exec("composer dump-autoload");

                Installation::configVich($generator, $io, "vich/uploader-bundle");
            }
        }

        $contenu = file_get_contents($entityPath);
        if(!str_contains($contenu, 'Vich\\UploaderBundle\\Mapping\\Annotation')) {
            $manipulator->addUseStatementIfNecessary('Vich\UploaderBundle\Mapping\Annotation as Vich');
        }

        if(!str_contains($contenu, 'Symfony\\Component\\HttpFoundation\\File\\File')) {
            $manipulator->addUseStatementIfNecessary('Symfony\Component\HttpFoundation\File\File');
        }

        if(!str_contains($contenu, 'Doctrine\\DBAL\\Types\\Types')) {
            $manipulator->addUseStatementIfNecessary('Doctrine\DBAL\Types\Types');
        }

        $generator->dumpFile($entityPath, $manipulator->getSourceCode());
        $generator->writeChanges();

        $contenu = file_get_contents($entityPath);

        if(!str_contains($contenu, '[Vich\Uploadable]')) {
            $patternSchema = '/class '.$class . '/';
            file_put_contents($entityPath, preg_replace($patternSchema, "#[Vich\Uploadable]\nclass " . $class, $contenu));
        }

        $contenu = file_get_contents($entityPath);
        $positionAttribut = strpos($contenu, 'private ?string $'. $newField->propertyName .' = null;');
        file_put_contents($entityPath ,substr_replace($contenu, "
    #[Vich\UploadableField(mapping: '". strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $newField->propertyName . $class)) ."', fileNameProperty: '". $newField->propertyName ."')]
    private ?File $". $newField->propertyName . 'File' ." = null;\n", $positionAttribut + strlen('private ?string $'. $newField->propertyName .' = null;') + 1, 0));

        $contenu = file_get_contents($entityPath);
        $positionFile = strpos($contenu, 'private ?File $'. $newField->propertyName .'File = null;');
        file_put_contents($entityPath ,substr_replace($contenu, "
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime \$updated".ucwords($newField->propertyName)."At = null;\n", $positionFile + strlen('private ?File $'. $newField->propertyName .'File = null;') + 1, 0));

        $manipulator = $this->createClassManipulator($entityPath, $io, false);
        $manipulator->addGetter("updated".ucwords($newField->propertyName)."At", '\DateTime', true, []);
        $manipulator->addSetter("updated".ucwords($newField->propertyName)."At", '\DateTime', true, []);
        $manipulator->addGetter($newField->propertyName .'File', 'File', true, []);

        $generator->dumpFile($entityPath, $manipulator->getSourceCode());
        $generator->writeChanges();

        $contenu = file_get_contents($entityPath);
        $positionGetter = strpos($contenu, "public function get".ucwords($newField->propertyName)."File()");
        file_put_contents($entityPath ,substr_replace($contenu, "
    public function set".ucwords($newField->propertyName)."File(File \$image = null)
    {
        \$this->".$newField->propertyName."File = \$image;

        if (\$image) {
            \$this->updated".ucwords($newField->propertyName)."At = new \DateTime('now');
        }
    }\n", $positionGetter + strlen(
                "public function get".ucwords($newField->propertyName)."File(): ?File
    {
        return \$this->".$newField->propertyName."File;
    }") + 1, 0));

        /**
         * Yaml
         */

        $path = $generator->getRootDirectory() . "/config/packages/vich_uploader.yaml";
        if(file_exists($path)) {
            $io->comment("\nConfiguration de vich uploader");
            $data = Yaml::parseFile($path);
            $nom = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $newField->propertyName . $class));
            $data["vich_uploader"]["mappings"][$nom]["uri_prefix"] = "%app.path." . $nom . "%";
            $data["vich_uploader"]["mappings"][$nom]["upload_destination"] = "%kernel.project_dir%/public/%app.path." . $nom . "%";

            $injectOnLoad = $io->ask("Injecter en charge (inject_on_load) (true/false)", "false");
            $data["vich_uploader"]["mappings"][$nom]["inject_on_load"] = filter_var($injectOnLoad, FILTER_VALIDATE_BOOLEAN);

            $deleteOnUpdate = $io->ask("Supprimer lors de la mise à jour (delete_on_update) (true/false)", "true");
            $data["vich_uploader"]["mappings"][$nom]["delete_on_update"] = filter_var($deleteOnUpdate, FILTER_VALIDATE_BOOLEAN);

            $deleteOnRemove = $io->ask("Supprimer lors de la suppression (delete_on_remove) (true/false)", "true");
            $data["vich_uploader"]["mappings"][$nom]["delete_on_remove"] = filter_var($deleteOnRemove, FILTER_VALIDATE_BOOLEAN);

            file_put_contents($path, Yaml::dump($data, 5, 4, Yaml::DUMP_NULL_AS_TILDE));
        }
        else {
            throw new \Exception("Vérifier si le Bundle est bien installer et si le fichier de configuration est bien existant");
        }

        $path = $generator->getRootDirectory() . "/config/services.yaml";
        if(file_exists($path)) {
            $data = Yaml::parseFile($path);

            if(isset($data["parameters"])) {
                foreach ($data["parameters"] as $key => $parameter) {
                    $data["parameters"][$key] = "'" . $data["parameters"][$key] . "'";
                }
            }

            $data["parameters"]["app.path." . $nom] = "'/uploads/" . strtolower($newField->propertyName) . "/" . strtolower($class) . "'";

            $resource = $data["services"]["App\\"]["resource"];
            $data["services"]["App\\"]["resource"] = "'" . $resource . "'";

            $excludes = [];

            foreach ($data["services"]["App\\"]["exclude"] as $service) {
                $excludes[] = "'" . $service . "'";
            }

            $data["services"]["App\\"]["exclude"] = $excludes;

            file_put_contents($path, str_replace("\"", '',Yaml::dump($data, 5)));
        }
        else {
            throw new \Exception("Le fichier services.ymal est inexistant et la configuration n'a pas pu être achevée entiérement.");
        }

        return $this->createClassManipulator($entityPath, $io, false);
    }

    public function timestampableType(Generator $generator,ConsoleStyle $io, $entityPath, $manipulator, $class, $classPath, $projetDir)
    {
        $content = file_get_contents($generator->getRootDirectory() . '/composer.json');
        $bundle = json_decode($content, true);

        if(!isset($bundle["require"]["stof/doctrine-extensions-bundle"])) {
            $question = new Question("Version de Stof (numéro de version ou last pour installer la plus récente)");
            $version = $io->askQuestion($question);
            if(preg_match("/^[0-9]{1,}\.[0-9]{1,}\.?[0-9]{1,}$/", $version) || $version == "last") {
                if($version == "last") {
                    $command = "composer require stof/doctrine-extensions-bundle";
                }
                else {
                    $command = "composer require stof/doctrine-extensions-bundle:" . $version;
                }
                exec($command);
                exec("composer dump-autoload");

                Installation::configStof($generator, $io, "stof/doctrine-extensions-bundle");
            }
        }

        $contenu = file_get_contents($entityPath);

        if(!str_contains($contenu, 'Gedmo\\Mapping\\Annotation')) {
            $manipulator->addUseStatementIfNecessary('Gedmo\Mapping\Annotation as Gedmo;');
        }

        if(!str_contains($contenu, 'Doctrine\\DBAL\\Types\\Types')) {
            $manipulator->addUseStatementIfNecessary('Doctrine\DBAL\Types\Types');
        }

        $generator->dumpFile($entityPath, $manipulator->getSourceCode());
        $generator->writeChanges();

        spl_autoload_register(function () use ($projetDir, $entityPath) {
            $file = $projetDir . '/' . $entityPath;

            if (file_exists($file)) {
                require $file;
            }
        });

        $contenu = file_get_contents($entityPath);

        if(!str_contains($contenu, "#[Gedmo\Timestampable(on: 'update')]")) {
            $reflectionClass = new \ReflectionClass($classPath);
            $property = $reflectionClass->getProperties()[count($reflectionClass->getProperties()) - 1];

            if(str_contains($property->getType(), "DateTime")) {
                $type = "?\\DateTime";
            }
            else
            {
                $type = $property->getType();
            }

            if($property->hasDefaultValue()) {
                $defaultValue = is_null($property->getDefaultValue()) ? 'null' : $property->getDefaultValue();
                $dernierAttribute = 'private ' . $type . ' $'. $property->getName() .' = '. $defaultValue .';';
            }
            else {
                $dernierAttribute = 'private ' . $type . ' $'. $property->getName() .';';
            }

            $positionFile = strpos($contenu, $dernierAttribute);
            file_put_contents($entityPath ,substr_replace($contenu, "
    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Gedmo\Timestampable(on: 'update')]
    private ?\DateTime \$updatedDate;\n", $positionFile + strlen($dernierAttribute) + 1, 0));
        }

        $contenu = file_get_contents($entityPath);

        if(!str_contains($contenu, "getUpdatedDate")) {

            file_put_contents($entityPath,  substr_replace($contenu,
                "
    public function getUpdatedDate()
    {
        return \$this->updatedDate;
    }\n", strrpos($contenu, '}'), 0));
        }

        $contenu = file_get_contents($entityPath);

        if(!str_contains($contenu, "getUpdatedDateToString")) {

            file_put_contents($entityPath,  substr_replace($contenu,
                "
    public function getUpdatedDateToString(): string
    {
        return \$this->updatedDate->format('d/m/Y') . ' - ' . \$this->updatedDate->format('H:i') . 'h';
    }\n", strrpos($contenu, '}'), 0));
        }

        $contenu = file_get_contents($entityPath);

        if(!str_contains($contenu, "setUpdatedDate")) {

            file_put_contents($entityPath,  substr_replace($contenu,
                "
    public function setUpdatedDate(\$updatedDate): void
    {
        \$this->updatedDate = \$updatedDate;
    }\n", strrpos($contenu, '}'), 0));
        }

        $contenu = file_get_contents($entityPath);

        if(!str_contains($contenu, "#[Gedmo\Timestampable(on: 'create')]")) {
            $reflectionClass = new \ReflectionClass($classPath);
            $property = $reflectionClass->getProperties()[count($reflectionClass->getProperties()) - 1];

            if(str_contains($property->getType(), "DateTime")) {
                $type = "?\\DateTime";
            }
            else
            {
                $type = $property->getType();
            }

            if($property->hasDefaultValue()) {
                $defaultValue = is_null($property->getDefaultValue()) ? 'null' : $property->getDefaultValue();
                $dernierAttribute = 'private ' . $type . ' $'. $property->getName() .' = '. $defaultValue .';';
            }
            else {
                $dernierAttribute = 'private ' . $type . ' $'. $property->getName() .';';
            }

            $positionFile = strpos($contenu, $dernierAttribute);
            file_put_contents($entityPath ,substr_replace($contenu, "
    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTime \$createdDate;\n", $positionFile + strlen($dernierAttribute) + 1, 0));
        }

        $contenu = file_get_contents($entityPath);

        if(!str_contains($contenu, "getCreatedDate")) {

            file_put_contents($entityPath,  substr_replace($contenu,
                "
    public function getCreatedDate()
    {
        return \$this->createdDate;
    }\n", strrpos($contenu, '}'), 0));
        }

        $contenu = file_get_contents($entityPath);

        if(!str_contains($contenu, "getCreatedDateToString")) {

            file_put_contents($entityPath,  substr_replace($contenu,
                "
    public function getCreatedDateToString(): string
    {
        return \$this->createdDate->format('d/m/Y') . ' - ' . \$this->createdDate->format('H:i') . 'h';
    }\n", strrpos($contenu, '}'), 0));
        }

        $contenu = file_get_contents($entityPath);

        if(!str_contains($contenu, "setCreatedDate")) {

            file_put_contents($entityPath,  substr_replace($contenu,
                "
    public function setCreatedDate(\$createdDate): void
    {
        \$this->createdDate = \$createdDate;
    }\n", strrpos($contenu, '}'), 0));
        }

        return $this->createClassManipulator($entityPath, $io, false);
    }

    public function blameableType(Generator $generator,ConsoleStyle $io, $entityPath, $manipulator, $class, $classPath, $projetDir)
    {
        $content = file_get_contents($generator->getRootDirectory() . '/composer.json');
        $bundle = json_decode($content, true);

        if(!isset($bundle["require"]["stof/doctrine-extensions-bundle"])) {
            $question = new Question("Version de Stof (numéro de version ou last pour installer la plus récente)");
            $version = $io->askQuestion($question);
            if(preg_match("/^[0-9]{1,}\.[0-9]{1,}\.?[0-9]{1,}$/", $version) || $version == "last") {
                if($version == "last") {
                    $command = "composer require stof/doctrine-extensions-bundle";
                }
                else {
                    $command = "composer require stof/doctrine-extensions-bundle:" . $version;
                }
                exec($command);
                exec("composer dump-autoload");

                Installation::configStof($generator, $io, "stof/doctrine-extensions-bundle");
            }
        }

        $contenu = file_get_contents($entityPath);

        if(!str_contains($contenu, 'Gedmo\\Mapping\\Annotation')) {
            $manipulator->addUseStatementIfNecessary('Gedmo\Mapping\Annotation as Gedmo;');
        }

        $generator->dumpFile($entityPath, $manipulator->getSourceCode());
        $generator->writeChanges();

        spl_autoload_register(function () use ($projetDir, $entityPath) {
            $file = $projetDir . '/' . $entityPath;

            if (file_exists($file)) {
                require $file;
            }
        });

        $contenu = file_get_contents($entityPath);

        if(!str_contains($contenu, "#[Gedmo\Blameable(on: 'update')]")) {
            $reflectionClass = new \ReflectionClass($classPath);
            $property = $reflectionClass->getProperties()[count($reflectionClass->getProperties()) - 1];

            if(str_contains($property->getType(), "DateTime")) {
                $type = "?\\DateTime";
            }
            else
            {
                $type = $property->getType();
            }

            if($property->hasDefaultValue()) {
                $defaultValue = is_null($property->getDefaultValue()) ? 'null' : $property->getDefaultValue();
                $dernierAttribute = 'private ' . $type . ' $'. $property->getName() .' = '. $defaultValue .';';
            }
            else {
                $dernierAttribute = 'private ' . $type . ' $'. $property->getName() .';';
            }

            $positionFile = strpos($contenu, $dernierAttribute);
            file_put_contents($entityPath ,substr_replace($contenu, "
    #[ORM\Column(length: 255, nullable: true)]
    #[Gedmo\Blameable(on: 'update')]
    private ?string \$updatedBy;\n", $positionFile + strlen($dernierAttribute) + 1, 0));
        }

        $contenu = file_get_contents($entityPath);

        if(!str_contains($contenu, "getUpdatedBy")) {

            file_put_contents($entityPath,  substr_replace($contenu,
                "
    public function getUpdatedBy()
    {
        return \$this->updatedBy;
    }\n", strrpos($contenu, '}'), 0));
        }

        $contenu = file_get_contents($entityPath);

        if(!str_contains($contenu, "getUpdatedByAlias")) {

            file_put_contents($entityPath,  substr_replace($contenu,
                "
    public function getUpdatedByAlias()
    {
        \$alias = explode('@', \$this->updatedBy);
        return \$alias[0];
    }\n", strrpos($contenu, '}'), 0));
        }

        $contenu = file_get_contents($entityPath);

        if(!str_contains($contenu, "setUpdatedBy")) {

            file_put_contents($entityPath,  substr_replace($contenu,
                "
    public function setUpdatedBy(\$updatedBy): void
    {
        \$this->updatedBy = \$updatedBy;
    }\n", strrpos($contenu, '}'), 0));
        }

        $contenu = file_get_contents($entityPath);

        if(!str_contains($contenu, "#[Gedmo\Blameable(on: 'create')]")) {
            $reflectionClass = new \ReflectionClass($classPath);
            $property = $reflectionClass->getProperties()[count($reflectionClass->getProperties()) - 1];

            if(str_contains($property->getType(), "DateTime")) {
                $type = "?\\DateTime";
            }
            else
            {
                $type = $property->getType();
            }

            if($property->hasDefaultValue()) {
                $defaultValue = is_null($property->getDefaultValue()) ? 'null' : $property->getDefaultValue();
                $dernierAttribute = 'private ' . $type . ' $'. $property->getName() .' = '. $defaultValue .';';
            }
            else {
                $dernierAttribute = 'private ' . $type . ' $'. $property->getName() .';';
            }

            $positionFile = strpos($contenu, $dernierAttribute);
            file_put_contents($entityPath ,substr_replace($contenu, "
    #[ORM\Column(length: 255, nullable: true)]
    #[Gedmo\Blameable(on: 'create')]
    private ?string \$createdBy;\n", $positionFile + strlen($dernierAttribute) + 1, 0));
        }

        $contenu = file_get_contents($entityPath);

        if(!str_contains($contenu, "getCreatedBy")) {

            file_put_contents($entityPath,  substr_replace($contenu,
                "
    public function getCreatedBy()
    {
        return \$this->createdBy;
    }\n", strrpos($contenu, '}'), 0));
        }

        $contenu = file_get_contents($entityPath);

        if(!str_contains($contenu, "getCreatedByAlias")) {

            file_put_contents($entityPath,  substr_replace($contenu,
                "
    public function getCreatedByAlias()
    {
        \$alias = explode('@', \$this->createdBy);
        return \$alias[0];
    }\n", strrpos($contenu, '}'), 0));
        }

        $contenu = file_get_contents($entityPath);

        if(!str_contains($contenu, "setCreatedBy")) {

            file_put_contents($entityPath,  substr_replace($contenu,
                "
    public function setCreatedBy(\$createdBy): void
    {
        \$this->createdBy = \$createdBy;
    }\n", strrpos($contenu, '}'), 0));
        }

        return $this->createClassManipulator($entityPath, $io, false);
    }

    public function sluggableType(Generator $generator,ConsoleStyle $io, $entityPath, $manipulator, $class, $classPath, $projetDir)
    {
        $content = file_get_contents($generator->getRootDirectory() . '/composer.json');
        $bundle = json_decode($content, true);

        if(!isset($bundle["require"]["stof/doctrine-extensions-bundle"])) {
            $question = new Question("Version de Stof (numéro de version ou last pour installer la plus récente)");
            $version = $io->askQuestion($question);
            if(preg_match("/^[0-9]{1,}\.[0-9]{1,}\.?[0-9]{1,}$/", $version) || $version == "last") {
                if($version == "last") {
                    $command = "composer require stof/doctrine-extensions-bundle";
                }
                else {
                    $command = "composer require stof/doctrine-extensions-bundle:" . $version;
                }
                exec($command);
                exec("composer dump-autoload");

                Installation::configStof($generator, $io, "stof/doctrine-extensions-bundle");
            }
        }

        $contenu = file_get_contents($entityPath);

        if(!str_contains($contenu, 'Gedmo\\Mapping\\Annotation')) {
            $manipulator->addUseStatementIfNecessary('Gedmo\Mapping\Annotation as Gedmo');
        }

        $generator->dumpFile($entityPath, $manipulator->getSourceCode());
        $generator->writeChanges();

        $contenu = file_get_contents($entityPath);

        spl_autoload_register(function () use ($projetDir, $entityPath) {
            $file = $projetDir . '/' . $entityPath;

            if (file_exists($file)) {
                require $file;
            }
        });

        $nomChamp = $io->ask("Renseigner le nom du slug.\n Le champ sera le nom que vous aurez renseigné et automatiquement préfixé par Slug.");

        if($nomChamp == null) {
            throw new Exception("Le nom du champ ne peut pas être vide");
        }

        $tailleChamp = $io->ask("Renseigner la longueur du champ.", 128);

        $reflectionClass = new \ReflectionClass($classPath);
        if(count($reflectionClass->getProperties()) > 0) {
            $champsReference = [];
            $stop = false;
            while(!$stop) {
                $proprietesAffichage = [];
                foreach ($reflectionClass->getProperties() as $property) {
                    if(!in_array($property->getName(), $champsReference)) {
                        $proprietesAffichage[] = $property->getName();
                    }
                }

                if(empty($proprietesAffichage)) {
                    $stop = true;
                    continue;
                }

                $champString = "";
                foreach ($proprietesAffichage as $key => $property) {
                    if($key != array_key_first($proprietesAffichage)) {
                        $champString .= " ";
                    }
                    $champString .= $property . "\n";
                }

                $question = new Question("Choisir un champ sluggable ? \n $champString");
                $question->setAutocompleterValues($proprietesAffichage);
                $champ = $io->askQuestion($question);
                if($champ == null) {
                    $stop = true;
                }
                else {
                    if(str_contains($champString, $champ)) {
                        $champsReference[] = $champ;
                    }
                    else {
                        $io->warning("Le champ renseigné n'est pas valide !");
                    }
                }
            }

            if(empty($champsReference)) {
                throw new Exception("Impossible d'utiliser un champ sluggable sur des champs vides");
            }
            else {
                $derniereProprieteClass = $reflectionClass->getProperties()[count($reflectionClass->getProperties()) - 1];

                if(str_contains($derniereProprieteClass->getType(), "DateTime")) {
                    $type = "?\\DateTime";
                }
                else
                {
                    $type = $derniereProprieteClass->getType();
                }

                if($derniereProprieteClass->hasDefaultValue()) {
                    $defaultValue = is_null($derniereProprieteClass->getDefaultValue()) ? 'null' : $derniereProprieteClass->getDefaultValue();
                    $derniereProprieteString = 'private ' . $type . ' $'. $derniereProprieteClass->getName() .' = '. $defaultValue .';';
                }
                else {
                    $derniereProprieteString = 'private ' . $type . ' $'. $derniereProprieteClass->getName() .';';
                }

                $champsPropriete = "[";
                foreach ($champsReference as $key => $item) {
                    $champsPropriete .= "'" . $item . "'";
                    if($key < count($champsReference) - 1) {
                        $champsPropriete .= ",";
                    }
                }
                $champsPropriete .= "]";

                if(!str_contains($contenu, "private ?string \$".strtolower($nomChamp)."Slug = null;")) {
                    $positionFile = strpos($contenu, $derniereProprieteString);
                    file_put_contents($entityPath ,substr_replace($contenu, "
    #[ORM\Column(length: ".$tailleChamp.", unique: true)]
    #[Gedmo\Slug(fields: ".$champsPropriete.")]
    private ?string \$".strtolower($nomChamp)."Slug = null;\n", $positionFile + strlen($derniereProprieteString) + 1, 0));

                    $contenu = file_get_contents($entityPath);

                    file_put_contents($entityPath,  substr_replace($contenu, "
    public function get".ucwords($nomChamp)."Slug()
    {
        return \$this->".strtolower($nomChamp)."Slug;
    }\n", strrpos($contenu, '}'), 0));
                }
                else {
                    $io->error("La propriété existe déjà !");
                }
            }
        }
        else {
            $io->warning("Aucun champ ne peux être sluggable !");
        }

        return $this->createClassManipulator($entityPath, $io, false);
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
}