<?php

namespace WebImpulse\SiteBundle\Maker;

use Exception;
use Symfony\Bundle\MakerBundle\ConsoleStyle;
use Symfony\Bundle\MakerBundle\DependencyBuilder;
use Symfony\Bundle\MakerBundle\Generator;
use Symfony\Bundle\MakerBundle\InputConfiguration;
use Symfony\Bundle\MakerBundle\Maker\AbstractMaker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Yaml\Yaml;

final class Installation extends AbstractMaker
{
    private array $types = [
        1 => "Stof",
        2 => "CKEditor",
        3 => "LiipImagine",
        4 => "Vich Uploader",
        5 => "EasyAdmin",
    ];
    public static function getCommandName(): string
    {
        return 'make:site:installation';
    }

    public function configureCommand(Command $command, InputConfiguration $inputConfig)
    {
        $command
            ->setDescription("Fait l'installation des Bundle nécessaire pour le projet");
    }

    public function configureDependencies(DependencyBuilder $dependencies)
    {
    }

    public function generate(InputInterface $input, ConsoleStyle $io, Generator $generator)
    {
        $installation = true;
        while ($installation) {
            $types = $this->types;
            $dataAttributes = array_map(function($value, $key) {
                return "    ".$key .'="'.$value.'"';
            }, array_values($types), array_keys($types));

            $dataAttributes = implode("\n", $dataAttributes);
            $question = new Question("Choisir le numéro du Bundle à installer.\n\n" . $dataAttributes . "\n\n");
            $bundle = $io->askQuestion($question);
            switch ($bundle) {
                case 1:
                    $this->askBundle($bundle, "stof/doctrine-extensions-bundle", $io, $generator);
                    break;
                case 2:
                    $this->askBundle($bundle, "friendsofsymfony/ckeditor-bundle", $io, $generator);
                    break;
                case 3:
                    $this->askBundle($bundle, "liip/imagine-bundle", $io, $generator);
                    break;
                case 4:
                    $this->askBundle($bundle, "vich/uploader-bundle", $io, $generator);
                    break;
                case 5:
                    $this->askBundle($bundle, "easycorp/easyadmin-bundle", $io, $generator);
                    break;
                default:
                    $installation = false;
                    break;
            }
        }
    }

    public function __call(string $name, array $arguments)
    {

    }

    public function askBundle($bundle, $repo, ConsoleStyle $io, Generator $generator) {
        $content = file_get_contents($generator->getRootDirectory() . '/composer.json');
        $data = json_decode($content, true);

        if(!isset($data["require"][$repo])) {
            $question = new Question("Version de ".$this->types[$bundle]." (numéro de version ou last pour installer la plus récente)");
            $version = $io->askQuestion($question);
            if(preg_match("/^[0-9]{1,}\.[0-9]{1,}\.?[0-9]{1,}$/", $version) || $version == "last") {
                if($version == "last") {
                    $command = "composer require ".$repo;
                }
                else {
                    $command = "composer require ".$repo.":" . $version;
                }
                exec($command);
                exec("composer dump-autoload");
            }
            else {
                throw new \InvalidArgumentException("Réponse accepter est une version au format '1.20' ou '1.20.23' ou 'last'");
            }
        }

        if($bundle == 1) {
            $this->configStof($generator, $io, $repo);
        }
        if($bundle == 2) {
            $this->configCKEditor($generator, $io, $repo);
        }
        if($bundle == 3) {
            $this->configLiip($generator, $io, $repo);
        }
        if($bundle == 4) {
            $this->configVich($generator, $io, $repo);
        }
    }

    public static function configStof(Generator $generator, ConsoleStyle $io, $repo): void
    {
        $path = $generator->getRootDirectory() . "/config/packages/stof_doctrine_extensions.yaml";
        if(file_exists($path)) {
            $data = Yaml::parseFile($path);
            $data["stof_doctrine_extensions"]["default_locale"] = "fr_FR";
            $timestampable = $io->ask("Timestampable, met à jour les champs de date lors de la création, de la mise à jour et même du changement de propriété.", "true");
            if($timestampable == null || $timestampable == "false" || $timestampable == "true")
            {
                $data["stof_doctrine_extensions"]["orm"]["default"]["timestampable"] = filter_var($timestampable, FILTER_VALIDATE_BOOLEAN);
            }
            $sluggable = $io->ask("Sluggable, rend vos champs spécifiés en un seul slug unique pour utilisé dans une URL.", "true");
            if($sluggable == null || $sluggable == "false" || $sluggable == "true")
            {
                $data["stof_doctrine_extensions"]["orm"]["default"]["sluggable"] = filter_var($sluggable, FILTER_VALIDATE_BOOLEAN);
            }
            $blameable = $io->ask("Blameable, met à jour les champs de chaîne ou de référence lors de la création, de la mise à jour et même du changement de propriété avec une chaîne ou un objet (par exemple, un utilisateur).", "true");
            if($blameable == null || $blameable == "false" || $blameable == "true")
            {
                $data["stof_doctrine_extensions"]["orm"]["default"]["blameable"] = filter_var($blameable, FILTER_VALIDATE_BOOLEAN);
            }
            $loggable = $io->ask("Loggable, permet de suivre les modifications et l'historique des objets, et prend également en charge la gestion des versions.", "true");
            if($loggable == null || $loggable == "false" || $loggable == "true")
            {
                $data["stof_doctrine_extensions"]["orm"]["default"]["loggable"] = filter_var($loggable, FILTER_VALIDATE_BOOLEAN);
            }
            $translatable = $io->ask("Translatable, offre une solution très pratique pour traduire des documents dans différentes langues. Facile à installer, facile à utiliser.", "true");
            if($translatable == null || $translatable == "false" || $translatable == "true")
            {
                $data["stof_doctrine_extensions"]["orm"]["default"]["translatable"] = filter_var($translatable, FILTER_VALIDATE_BOOLEAN);
            }
            file_put_contents($path, Yaml::dump($data, 20, 4, Yaml::DUMP_NULL_AS_TILDE));
        }
        else {
            exec("composer remove " . $repo);
            exec("composer dump-autoload");
        }
    }

    public static function configLiip(Generator $generator, ConsoleStyle $io, $repo): void
    {
        $path = $generator->getRootDirectory() . "/config/packages/liip_imagine.yaml";
        if(file_exists($path)) {
            $data = Yaml::parseFile($path);
            $question = new Question("Modifier le driver Liip_imagine ? (options possible gd, imagick ou gmagick)", "gd");
            $driver = $io->askQuestion($question);
            if($driver == "gd" || $driver == "imagick" || $driver == "gmagick")
            {
                if($driver != $data["liip_imagine"]["driver"] || $data == null)
                {
                    $data["liip_imagine"]["driver"] = "\"" . $driver . "\"";
                }
            }
            else
            {
                $data["liip_imagine"]["driver"] = "\"gd\"";
            }

            $question = new Question("Cache LiipImagine ?", "default");
            $cache = $io->askQuestion($question);
            if($cache == null) {
                $data["liip_imagine"]["cache"] = "\"default\"";
            }
            else {
                $data["liip_imagine"]["cache"] = "\"".$cache."\"";
            }
            $question = new Question("Cache base path LiipImagine ?", "''");
            $cache_base_path = $io->askQuestion($question);
            if($cache_base_path == null) {
                $data["liip_imagine"]["cache_base_path"] = "\"\"";
            }
            else {
                $data["liip_imagine"]["cache_base_path"] = "\"".$cache_base_path."\"";
            }
            $question = new Question("Data loader LiipImagine ?", "default");
            $data_loader = $io->askQuestion($question);
            if($data_loader == null) {
                $data["liip_imagine"]["data_loader"] = "\"default\"";
            }
            else {
                $data["liip_imagine"]["data_loader"] = "\"".$data_loader."\"";
            }


            $question = new Question("Modifier web path du resolver ? (O\N)", "~");
            $webPath = $io->askQuestion($question);
            if($webPath == "O") {
                $webRoot = $io->ask("Web root");
                $data["liip_imagine"]["resolvers"]["default"]["web_path"]["web_root"] = "\"" . $webRoot . "\"";
                $cachePrefix = $io->ask("Cache prefix");
                $data["liip_imagine"]["resolvers"]["default"]["web_path"]["cache_prefix"] = "\"" . $cachePrefix . "\"";
            }
            elseif($webPath == "N") {
                $data['liip_imagine']["resolvers"] = [
                    "default" => [
                        "web_path" => null]];
            }

            $question = new Question("Mise en place des images webp ? (O\N)");
            $webp = $io->askQuestion($question);
            if($webp == "O") {
                $data['liip_imagine']["webp"]["generate"] = true;
                $qualite = $io->ask("Qualité des images webp");
                if(is_int($qualite) && $qualite > 0 && $qualite < 100) {
                    $data['liip_imagine']["webp"]["quality"] = $qualite;
                }
                else {
                    $data['liip_imagine']["webp"]["quality"] = 100;
                }
                $cache = $io->ask("Cache des images webp", "~");
                if($cache == null) {
                    $data['liip_imagine']["webp"]["cache"] = null;
                }
                else {
                    $data['liip_imagine']["webp"]["cache"] = "\"" . $cache . "\"";
                }
                $data_loader = $io->ask("Data loader des images webp", "~");
                if($data_loader == null) {
                    $data['liip_imagine']["webp"]["data_loader"] = null;
                }
                else {
                    $data['liip_imagine']["webp"]["data_loader"] = "\"" . $data_loader . "\"";
                }
                $data['liip_imagine']["webp"]["post_processors"] = "[]";
            }

            $data['liip_imagine']['filter_sets'] = "";

            file_put_contents($path, str_replace("'", '',Yaml::dump($data, 20, 4, Yaml::DUMP_NULL_AS_TILDE)));
        }
        else {
            exec("composer remove " . $repo);
            exec("composer dump-autoload");
        }
    }

    public static function configVich(Generator $generator, ConsoleStyle $io, $repo) {
        $path = $generator->getRootDirectory() . "/config/packages/vich_uploader.yaml";
        if(file_exists($path)) {
            $data = Yaml::parseFile($path);
            $data["vich_uploader"]["metadata"]["type"] = "attribute";
            $data["vich_uploader"]["db_driver"] = "orm";

            file_put_contents($path, Yaml::dump($data, 4, 4, Yaml::DUMP_NULL_AS_TILDE));
        }
        else {
            exec("composer remove " . $repo);
            exec("composer dump-autoload");
        }
    }

    public static function configCKEditor(Generator $generator, ConsoleStyle $io, $repo) {
        $path = $generator->getRootDirectory() . "/config/packages/fos_ckeditor.yaml";

        if(file_exists($path)) {
            $data = Yaml::parseFile($path);

            $data["twig"]["form_themes"] = ["\"@FOSCKEditor/Form/ckeditor_widget.html.twig\""];

            $data["fos_ck_editor"] = [
                "input_sync" => false,
                "configs" => [
                    "basic_conf" => [
                        "toolbar" => [
                            [
                                "name" => "\"styles\"",
                                "items" => [
                                    "\"Format\"",
                                    "\"SpecialChar\"",
                                    "\"Bold\"",
                                    "\"Italic\"",
                                    "\"Superscript\"",
                                    "\"Underline\"",
                                    "\"Blockquote\""
                                ]
                            ],
                            [
                                "name" => "\"colors\"",
                                "items" => [
                                    "\"TextColor\""
                                ]
                            ],
                            [
                                "name" => "\"clipboard\"",
                                "items" => [
                                    "\"Undo\"",
                                    "\"Redo\""
                                ]
                            ],
                            [
                                "name" => "\"paragraph\"",
                                "items" => [
                                    "\"NumberedList\"",
                                    "\"BulletedList\"",
                                    "\"-\"",
                                    "\"JustifyLeft\"",
                                    "\"JustifyCenter\"",
                                    "\"JustifyRight\"",
                                ]
                            ],
                            [
                                "name" => "\"links\"",
                                "items" => [
                                    "\"Link\"",
                                    "\"Unlink\"",
                                    "\"button\""
                                ]
                            ],
                            [
                                "name" => "\"insert\"",
                                "items" => [
                                    "\"HorizontalRule\"",
                                    "\"Image\"",
                                    "\"Table\"",
                                    "\"Youtube\"",
                                    "\"Embed\"",
                                    "\"Basewidget\"",
                                    "\"widget\"",
                                    "\"CollapsibleItem\"",
                                ]
                            ],
                            [
                                "name" => "\"tools\"",
                                "items" => [
                                    "\"Maximize\"",
                                    "\"Source\""
                                ]
                            ]
                        ],
                        "extraPlugins" => "\"widget, basewidget, youtube, dialog, fakeobjects, image2, notification, notificationaggregator, embedbase, embed, uploadwidget, collapsibleItem, btgrid, divarea\"",
                        "entities" => false,
                        "htmlEncodeOutput" => false,
                        "height" => "\"500px\"",
                        "language" => "\"fr\"",
                        "embed_provider" => "\"//ckeditor.iframe.ly/api/oembed?url={url}&callback={callback}\"",
                        "extraAllowedContent" => "\"iframe[*]; oembed(*){*}[*];\"",
                        "format_tags" => "\"pre;p;h2;h3;div\"",
                        "forcePasteAsPlainText" => true,
                    ],
                    "min_config" => [
                        "toolbar" => [
                            [
                                "name" => "\"styles\"",
                                "items" => [
                                    "\"Format\"",
                                    "\"SpecialChar\"",
                                    "\"Bold\"",
                                    "\"Italic\"",
                                    "\"Superscript\""
                                ]
                            ],
                            [
                                "name" => "\"paragraph\"",
                                "items" => [
                                    "\"NumberedList\"",
                                    "\"BulletedList\""
                                ]
                            ],
                            [
                                "name" => "\"links\"",
                                "items" => [
                                    "\"Link\"",
                                    "\"Unlink\""
                                ]
                            ]
                        ],
                        "height" => "\"100px\"",
                        "language" => "\"fr\"",
                        "format_tags" => "\"p;h3;div\""
                    ]
                ],
                "plugins" => [
                    "widget" => [
                        "path" => "\"../vendor/webimpulse/bundle-site/src/Resources/build/ckeditor/extra-plugins/widget/\"",
                        "filename" => "\"plugin.js\""
                    ],
                    "uploadwidget" => [
                        "path" => "\"../vendor/webimpulse/bundle-site/src/Resources/build/ckeditor/extra-plugins/uploadwidget/\"",
                        "filename" => "\"plugin.js\""
                    ],
                    "basewidget" => [
                        "path" => "\"../vendor/webimpulse/bundle-site/src/Resources/build/ckeditor/extra-plugins/basewidget/\"",
                        "filename" => "\"plugin.js\""
                    ],
                    "fakeobjects" => [
                        "path" => "\"../vendor/webimpulse/bundle-site/src/Resources/build/ckeditor/extra-plugins/fakeobjects/\"",
                        "filename" => "\"plugin.js\""
                    ],
                    "image2" => [
                        "path" => "\"../vendor/webimpulse/bundle-site/src/Resources/build/ckeditor/extra-plugins/image2/\"",
                        "filename" => "\"plugin.js\""
                    ],
                    "notification" => [
                        "path" => "\"../vendor/webimpulse/bundle-site/src/Resources/build/ckeditor/extra-plugins/notification/\"",
                        "filename" => "\"plugin.js\""
                    ],
                    "notificationaggregator" => [
                        "path" => "\"../vendor/webimpulse/bundle-site/src/Resources/build/ckeditor/extra-plugins/notificationaggregator/\"",
                        "filename" => "\"plugin.js\""
                    ],
                    "embedbase" => [
                        "path" => "\"../vendor/webimpulse/bundle-site/src/Resources/build/ckeditor/extra-plugins/embedbase/\"",
                        "filename" => "\"plugin.js\""
                    ],
                    "embed" => [
                        "path" => "\"../vendor/webimpulse/bundle-site/src/Resources/build/ckeditor/extra-plugins/embed/\"",
                        "filename" => "\"plugin.js\""
                    ],
                    "youtube" => [
                        "path" => "\"../vendor/webimpulse/bundle-site/src/Resources/build/ckeditor/extra-plugins/youtube/\"",
                        "filename" => "\"plugin.js\""
                    ],
                    "dialog" => [
                        "path" => "\"../vendor/webimpulse/bundle-site/src/Resources/build/ckeditor/extra-plugins/dialog/\"",
                        "filename" => "\"plugin.js\""
                    ],
                    "button" => [
                        "path" => "\"../vendor/webimpulse/bundle-site/src/Resources/build/ckeditor/extra-plugins/button/\"",
                        "filename" => "\"plugin.js\""
                    ],
                    "collapsibleItem" => [
                        "path" => "\"../vendor/webimpulse/bundle-site/src/Resources/build/ckeditor/extra-plugins/collapsibleItem/\"",
                        "filename" => "\"plugin.js\""
                    ],
                    "btgrid" => [
                        "path" => "\"../vendor/webimpulse/bundle-site/src/Resources/build/ckeditor/extra-plugins/btgrid/\"",
                        "filename" => "\"plugin.js\""
                    ],
                    "divarea" => [
                        "path" => "\"../vendor/webimpulse/bundle-site/src/Resources/build/ckeditor/extra-plugins/divarea/\"",
                        "filename" => "\"plugin.js\""
                    ]
                ]
            ];

            $data["fos_ck_editor"]["configs"]["min_config"]["contentsCss"] = "\"css/ckeditor.css\"";
            $data["fos_ck_editor"]["configs"]["basic_conf"]["contentsCss"] = "\"admin/css/ckeditor-portail.css\"";

            file_put_contents($path, str_replace("'", '',Yaml::dump($data, 5, 4, Yaml::DUMP_NULL_AS_TILDE)));
        }
        else {
            exec("composer remove " . $repo);
            exec("composer dump-autoload");
        }
    }
}
