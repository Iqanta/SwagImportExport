<?php
/**
 * (c) shopware AG <info@shopware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Shopware\Commands\SwagImportExport;

use Shopware\Commands\ShopwareCommand;
use Shopware\Components\Model\ModelManager;
use Shopware\Components\SwagImportExport\Utils\CommandHelper;
use Shopware\CustomModels\ImportExport\Profile;
use Shopware\CustomModels\ImportExport\Repository;
use Shopware\Models\Order\Order;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ExportNewOrdersCommand extends ShopwareCommand
{
    const DEFAULT_FORMAT = 'xml';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('sw:importexport:export-new-orders')
            ->setDescription('Export new orders to files.')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'Which profile will be used?')
            ->addOption('format', 'f', InputOption::VALUE_OPTIONAL, 'What is the format of the exported file - XML or CSV?')
            ->setHelp('The <info>%command.name%</info> exports new orders to files.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Validation of user input
        $validatedInputVars = $this->prepareExportInputValidation($input);

        $this->registerErrorHandler($output);

        $orders = $this->getNewOrders();
        $orderFiles = [];

        foreach ($orders as $order) {
            $filePath = [
                'tmpFile' => tempnam(sys_get_temp_dir(), 'OB_ORDER_'),
                'destFile' => $this->getFilePath($order['number'], $validatedInputVars['format'])
            ];

            $output->writeln('<info>' . sprintf('Write to file: %s.', $filePath['tmpFile']) . '</info>');
            $helper = new CommandHelper(
                [
                    'profileEntity' => $validatedInputVars['profileEntity'],
                    'format' => $validatedInputVars['format'],
                    'username' => 'Commandline',
                    'filePath' => $filePath['tmpFile'],
                    'order' => $this->getOrderByNumber($order['number']),
                ]
            );

            $preparationData = $helper->prepareExport();
            $count = $preparationData['count'];
            $output->writeln('<info>' . sprintf('Total count: %d.', $count) . '</info>');
            $position = 0;
            while ($position < $count) {
                $data = $helper->exportAction();
                $position = $data['position'];
                $output->writeln('<info>' . sprintf('Processed: %d.', $position) . '</info>');
                $this->markOrderAsExported($order['id']);
            }

            array_push($orderFiles, $filePath);
        }

        foreach ($orderFiles as $filePath) {
            $output->writeln('<info>' . sprintf('Move to file: %s.', $filePath['destFile']) . '</info>');
            rename($filePath['tmpFile'], $filePath['destFile']);
        }

        if (empty($orders)) {
            $output->writeln('<info>No orders to export found</info>');
        }
    }

    /**
     * @param InputInterface $input
     *
     * @return array
     * @throws \Exception
     */
    protected function prepareExportInputValidation(InputInterface $input): array
    {
        $profile = $input->getOption('profile');
        $format = $input->getOption('format');

        /** @var ModelManager $em */
        $em = $this->container->get('models');

        /** @var Repository $profileRepository */
        $profileRepository = $em->getRepository('Shopware\CustomModels\ImportExport\Profile');

        /* @var Profile profileEntity */
        $profileEntity = $profileRepository->findOneBy(['name' => $profile]);
        $this->validateProfiles($profileEntity, $profile);

        // if no format is specified default format comes in place
        if (empty($format)) {
            $format = self::DEFAULT_FORMAT;
        }

        // validate type
        $format = strtolower(trim($format));
        if (!in_array($format, ['csv', 'xml'])) {
            throw new \Exception(sprintf('Invalid format: \'%s\'! Valid formats are: CSV and XML.', $format));
        }

        return [
            'profileEntity' => $profileEntity,
            'format' => $format
        ];
    }

    /**
     * @param $orderNumber
     *
     * @return Order
     * @throws \Exception
     */
    protected function getOrderByNumber($orderNumber): Order
    {
        return $this->getOrder(['number' => $orderNumber]);
    }

    /**
     * @param $id
     *
     * @return Order
     * @throws \Exception
     */
    protected function getOrderById($id): Order
    {
        return $this->getOrder(['id' => $id]);
    }

    /**
     * @param array $criteria
     *
     * @return Order
     * @throws \Exception
     */
    protected function getOrder(array $criteria): Order
    {
        $orderRepository = $this->getContainer()->get('models')->getRepository(Order::class);
        $order = $orderRepository->findOneBy($criteria);
        $this->validateOrder($order, $criteria);
        return $order;
    }

    /**
     * @return array
     */
    protected function getNewOrders(): array
    {
        $modelManager = $this->getContainer()->get('models');
        $query = $modelManager->createQueryBuilder();

        $query->select(['o.number', 'o.id'])
            ->from('Shopware\Models\Order\Order', 'o')
            ->leftJoin('Shopware\Models\Attribute\Order', 'a', 'WITH', 'o.id = a.orderId')
            ->where("o.number != '0' AND o.number != '' AND (a.stepExported IS NULL OR a.stepExported = 0)");

        $orders = $query->getQuery()->execute();

        return !is_array($orders) ? [] : $orders;
    }

    /**
     * @param $profileEntity
     * @param $profile
     *
     * @throws \Exception
     */
    protected function validateProfiles($profileEntity, $profile)
    {
        if (!$profileEntity) {
            throw new \Exception(sprintf('Invalid profile: \'%s\'!', $profile));
        }
    }

    /**
     * @param $order
     * @param array $criteria
     *
     * @throws \Exception
     */
    protected function validateOrder($order, array $criteria)
    {
        if (!$order instanceof Order) {
            throw new \Exception(sprintf("Invalid order criteria! There is no order with this criteria: %s",
                implode(', ', array_map(
                    function ($v, $k) {
                        return sprintf("%s='%s'", $k, $v);
                    },
                    $criteria,
                    array_keys($criteria)
                ))));
        }
    }

    /**
     * @param $orderNumber
     *
     * @return string
     * @throws \Exception
     */
    protected function getFilePath($orderNumber, $format) {
        $directory = Shopware()->DocPath() . 'export/step/';
        $this->ensureDirectoryExists($directory);
        return sprintf('%sstep_order_%s.%s', $directory, $orderNumber, $format);
    }

    /**
     * @param $directory
     *
     * @throws \Exception
     */
    protected function ensureDirectoryExists($directory) {
        if (!is_dir($directory)) {
            $result = mkdir($directory, 0755, true);
            if ($result !== true) {
                throw new \Exception(sprintf("Unable to create directory: %s!", $directory));
            }
        }
    }

    /**
     * @param $orderId
     *
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    protected function markOrderAsExported($orderId) {
        $order = $this->getOrderById($orderId);
        $modelManager = $this->getContainer()->get('models');
        $order->getAttribute()->setStepExported(1);
        $modelManager->persist($order);
        $modelManager->flush();
    }
}
