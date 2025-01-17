<?php

declare(strict_types=1);

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\AdminBundle\Command;

use Sensio\Bundle\GeneratorBundle\SensioGeneratorBundle;
use Sonata\AdminBundle\Admin\Pool;
use Sonata\AdminBundle\Controller\CRUDController;
use Sonata\AdminBundle\Generator\AdminGenerator;
use Sonata\AdminBundle\Generator\ControllerGenerator;
use Sonata\AdminBundle\Manipulator\ServicesManipulator;
use Sonata\AdminBundle\Model\ModelManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * @author Marek Stipek <mario.dweller@seznam.cz>
 * @author Simon Cosandey <simon.cosandey@simseo.ch>
 */
class GenerateAdminCommand extends QuestionableCommand
{
    /**
     * {@inheritdoc}
     */
    protected static $defaultName = 'sonata:admin:generate';

    /**
     * @var Pool
     */
    private $pool;

    /**
     * An array of model managers indexed by their service ids.
     *
     * @var ModelManagerInterface[]
     */
    private $managerTypes = [];

    public function __construct(Pool $pool, array $managerTypes)
    {
        $this->pool = $pool;
        $this->managerTypes = $managerTypes;

        parent::__construct();
    }

    public function configure()
    {
        $this
            ->setDescription('Generates an admin class based on the given model class')
            ->setName(static::$defaultName)// BC for symfony/console < 3.4.0
            // NEXT_MAJOR: drop this line after drop support symfony/console < 3.4.0
            ->addArgument('model', InputArgument::REQUIRED, 'The fully qualified model class')
            ->addOption('bundle', 'b', InputOption::VALUE_OPTIONAL, 'The bundle name')
            ->addOption('admin', 'a', InputOption::VALUE_OPTIONAL, 'The admin class basename')
            ->addOption('controller', 'c', InputOption::VALUE_OPTIONAL, 'The controller class basename')
            ->addOption('manager', 'm', InputOption::VALUE_OPTIONAL, 'The model manager type')
            ->addOption('services', 'y', InputOption::VALUE_OPTIONAL, 'The services YAML file', 'services.yml')
            ->addOption('id', 'i', InputOption::VALUE_OPTIONAL, 'The admin service ID')
        ;
    }

    public function isEnabled()
    {
        return class_exists(SensioGeneratorBundle::class);
    }

    /**
     * @param string $managerType
     *
     * @throws \InvalidArgumentException
     *
     * @return string
     */
    public function validateManagerType($managerType)
    {
        $managerTypes = $this->getAvailableManagerTypes();

        if (!isset($managerTypes[$managerType])) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid manager type "%s". Available manager types are "%s".',
                $managerType,
                implode('", "', array_keys($managerTypes))
            ));
        }

        return $managerType;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $modelClass = Validators::validateClass($input->getArgument('model'));
        $modelClassBasename = current(\array_slice(explode('\\', $modelClass), -1));
        $bundle = $this->getBundle($input->getOption('bundle') ?: $this->getBundleNameFromClass($modelClass));
        $adminClassBasename = $input->getOption('admin') ?: $modelClassBasename.'Admin';
        $adminClassBasename = Validators::validateAdminClassBasename($adminClassBasename);
        $managerType = $input->getOption('manager') ?: $this->getDefaultManagerType();
        $modelManager = $this->getModelManager($managerType);
        $skeletonDirectory = __DIR__.'/../Resources/skeleton';
        $adminGenerator = new AdminGenerator($modelManager, $skeletonDirectory);

        try {
            $adminGenerator->generate($bundle, $adminClassBasename, $modelClass);
            $output->writeln(sprintf(
                '%sThe admin class "<info>%s</info>" has been generated under the file "<info>%s</info>".',
                PHP_EOL,
                $adminGenerator->getClass(),
                realpath($adminGenerator->getFile())
            ));
        } catch (\Exception $e) {
            $this->writeError($output, $e->getMessage());
        }

        $controllerName = CRUDController::class;

        if ($controllerClassBasename = $input->getOption('controller')) {
            $controllerClassBasename = Validators::validateControllerClassBasename($controllerClassBasename);
            $controllerGenerator = new ControllerGenerator($skeletonDirectory);

            try {
                $controllerGenerator->generate($bundle, $controllerClassBasename);
                $controllerName = $controllerGenerator->getClass();
                $output->writeln(sprintf(
                    '%sThe controller class "<info>%s</info>" has been generated under the file "<info>%s</info>".',
                    PHP_EOL,
                    $controllerName,
                    realpath($controllerGenerator->getFile())
                ));
            } catch (\Exception $e) {
                $this->writeError($output, $e->getMessage());
            }
        }

        if ($servicesFile = $input->getOption('services')) {
            $adminClass = $adminGenerator->getClass();
            $file = sprintf('%s/Resources/config/%s', $bundle->getPath(), $servicesFile);
            $servicesManipulator = new ServicesManipulator($file);

            try {
                $id = $input->getOption('id') ?: $this->getAdminServiceId($bundle->getName(), $adminClassBasename);
                $servicesManipulator->addResource($id, $modelClass, $adminClass, $controllerName, $managerType);
                $output->writeln(sprintf(
                    '%sThe service "<info>%s</info>" has been appended to the file <info>"%s</info>".',
                    PHP_EOL,
                    $id,
                    realpath($file)
                ));
            } catch (\Exception $e) {
                $this->writeError($output, $e->getMessage());
            }
        }

        return 0;
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $questionHelper = $this->getQuestionHelper();
        $questionHelper->writeSection($output, 'Welcome to the Sonata admin generator');
        $modelClass = $this->askAndValidate(
            $input,
            $output,
            'The fully qualified model class',
            $input->getArgument('model'),
            'Sonata\AdminBundle\Command\Validators::validateClass'
        );
        $modelClassBasename = current(\array_slice(explode('\\', $modelClass), -1));
        $bundleName = $this->askAndValidate(
            $input,
            $output,
            'The bundle name',
            $input->getOption('bundle') ?: $this->getBundleNameFromClass($modelClass),
            'Sensio\Bundle\GeneratorBundle\Command\Validators::validateBundleName'
        );
        $adminClassBasename = $this->askAndValidate(
            $input,
            $output,
            'The admin class basename',
            $input->getOption('admin') ?: $modelClassBasename.'Admin',
            'Sonata\AdminBundle\Command\Validators::validateAdminClassBasename'
        );

        if (\count($this->getAvailableManagerTypes()) > 1) {
            $managerType = $this->askAndValidate(
                $input,
                $output,
                'The manager type',
                $input->getOption('manager') ?: $this->getDefaultManagerType(),
                [$this, 'validateManagerType']
            );
            $input->setOption('manager', $managerType);
        }

        if ($this->askConfirmation($input, $output, 'Do you want to generate a controller', 'no', '?')) {
            $controllerClassBasename = $this->askAndValidate(
                $input,
                $output,
                'The controller class basename',
                $input->getOption('controller') ?: $modelClassBasename.'AdminController',
                'Sonata\AdminBundle\Command\Validators::validateControllerClassBasename'
            );
            $input->setOption('controller', $controllerClassBasename);
        }

        if ($this->askConfirmation($input, $output, 'Do you want to update the services YAML configuration file', 'yes', '?')) {
            $path = $this->getBundle($bundleName)->getPath().'/Resources/config/';
            $servicesFile = $this->askAndValidate(
                $input,
                $output,
                'The services YAML configuration file',
                is_file($path.'admin.yml') ? 'admin.yml' : 'services.yml',
                'Sonata\AdminBundle\Command\Validators::validateServicesFile'
            );
            $id = $this->askAndValidate(
                $input,
                $output,
                'The admin service ID',
                $this->getAdminServiceId($bundleName, $adminClassBasename),
                'Sonata\AdminBundle\Command\Validators::validateServiceId'
            );
            $input->setOption('services', $servicesFile);
            $input->setOption('id', $id);
        } else {
            $input->setOption('services', false);
        }

        $input->setArgument('model', $modelClass);
        $input->setOption('admin', $adminClassBasename);
        $input->setOption('bundle', $bundleName);
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function getBundleNameFromClass(string $class): ?string
    {
        $application = $this->getApplication();
        /* @var $application Application */

        foreach ($application->getKernel()->getBundles() as $bundle) {
            if (0 === strpos($class, $bundle->getNamespace().'\\')) {
                return $bundle->getName();
            }
        }

        return null;
    }

    private function getBundle(string $name): BundleInterface
    {
        return $this->getKernel()->getBundle($name);
    }

    private function writeError(OutputInterface $output, string $message): void
    {
        $output->writeln(sprintf("\n<error>%s</error>", $message));
    }

    /**
     * @throws \RuntimeException
     */
    private function getDefaultManagerType(): string
    {
        if (!$managerTypes = $this->getAvailableManagerTypes()) {
            throw new \RuntimeException('There are no model managers registered.');
        }

        return current(array_keys($managerTypes));
    }

    private function getModelManager(string $managerType): ModelManagerInterface
    {
        $modelManager = $this->getAvailableManagerTypes()[$managerType];
        \assert($modelManager instanceof ModelManagerInterface);

        return $modelManager;
    }

    private function getAdminServiceId(string $bundleName, string $adminClassBasename): string
    {
        $prefix = 'Bundle' === substr($bundleName, -6) ? substr($bundleName, 0, -6) : $bundleName;
        $suffix = 'Admin' === substr($adminClassBasename, -5) ? substr($adminClassBasename, 0, -5) : $adminClassBasename;
        $suffix = str_replace('\\', '.', $suffix);

        return Container::underscore(sprintf(
            '%s.admin.%s',
            $prefix,
            $suffix
        ));
    }

    /**
     * @return string[]
     */
    private function getAvailableManagerTypes(): array
    {
        $managerTypes = [];
        foreach ($this->managerTypes as $id => $manager) {
            $managerType = substr($id, 21);
            $managerTypes[$managerType] = $manager;
        }

        return $managerTypes;
    }

    private function getKernel(): KernelInterface
    {
        /* @var $application Application */
        $application = $this->getApplication();

        return $application->getKernel();
    }
}
