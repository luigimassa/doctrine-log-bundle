<?php

namespace Mb\DoctrineLogBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use JMS\Serializer\SerializerInterface as Serializer;
use Mb\DoctrineLogBundle\Entity\Log as LogEntity;
use Mb\DoctrineLogBundle\Service\AnnotationReader;
use Mb\DoctrineLogBundle\Service\Logger as LoggerService;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

/**
 * Class Logger
 * @package CoreBundle\EventListener
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter.Unused)
 */
class Logger
{
    /**
     * @var array
     */
    protected $logs;

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var Serializer
     */
    private $serializer;

    /**
     * @var AnnotationReader
     */
    private $reader;

    /**
     * @var LoggerInterface
     */
    private $monolog;

    /**
     * @var array
     */
    private $ignoreProperties;

    /**
     * Logger constructor.
     *
     * @param EntityManagerInterface $em
     * @param Serializer $serializer
     * @param AnnotationReader $reader
     * @param array $ignoreProperties
     */
    public function __construct(
        EntityManagerInterface $em,
        Serializer             $serializer,
        AnnotationReader       $reader,
        LoggerInterface        $monolog,
        array                  $ignoreProperties
    )
    {
        $this->em = $em;
        $this->serializer = $serializer;
        $this->reader = $reader;
        $this->ignoreProperties = $ignoreProperties;
        $this->monolog = $monolog;
    }

    /**
     * Flush logs. Can't flush inside post update
     *
     * @param PostFlushEventArgs $args
     */
    public function postFlush(PostFlushEventArgs $args)
    {
        if (!empty($this->logs)) {
            foreach ($this->logs as $log) {
                $this->em->persist($log);
            }

            $this->logs = [];
            $this->em->flush();
        }
    }

    /**
     * Post persist listener
     *
     * @param LifecycleEventArgs $args
     */
    public function postPersist(LifecycleEventArgs $args)
    {
        $entity = $args->getObject();
        $this->log($entity, LogEntity::ACTION_CREATE);
    }

    /**
     * Log the action
     *
     * @param object $entity
     * @param string $action
     */
    private function log($entity, $action)
    {
        try {
            $this->reader->init($entity);
            if ($this->reader->isLoggable()) {
                $changes = null;
                if ($action === LogEntity::ACTION_UPDATE) {
                    $uow = $this->em->getUnitOfWork();

                    // get changes => should be already computed here (is a listener)
                    $changeSet = $uow->getEntityChangeSet($entity);
                    // if we have no changes left => don't create revision log
                    if (count($changeSet) == 0) {
                        return;
                    }

                    // just getting the changed objects ids
                    foreach ($changeSet as $key => &$values) {
                        if (in_array($key, $this->ignoreProperties) || !$this->reader->isLoggable($key)) {
                            // ignore configured properties
                            unset($changeSet[$key]);
                        }

                        if (is_object($values[0]) && method_exists($values[0], 'getId')) {
                            $values[0] = $values[0]->getId();
                        }

                        if (is_object($values[1]) && method_exists($values[1], 'getId')) {
                            $values[1] = $values[1]->getId();
                        } elseif ($values[1] instanceof StreamInterface) {
                            $values[1] = (string)$values[1];
                        }
                    }

                    if (!empty($changeSet)) {
                        $changes = $this->serializer->serialize($changeSet, 'json');
                    }
                }

                if ($action === LogEntity::ACTION_UPDATE && !$changes) {
                    // Log nothing
                } else {
                    $this->logs[] = $this->createLogEntity(
                        $entity,
                        $action,
                        $changes
                    );
                }
            }
        } catch (\Exception $e) {
            $this->monolog->error($e->getMessage());
        }
    }

    /**
     * Creates the log entity
     *
     * @param object $object
     * @param string $action
     * @param string $changes
     * @return LogEntity
     */
    private function createLogEntity($object, $action, $changes = null): LogEntity
    {
        $log = new LogEntity();
        $log
            ->setObjectClass(str_replace('Proxies\__CG__\\', '', get_class($object)))
            ->setForeignKey($object->getId())
            ->setAction($action)
            ->setChanges($changes);

        return $log;
    }

    /**
     * Pre remove listener
     *
     * @param LifecycleEventArgs $args
     */
    public function postRemove(LifecycleEventArgs $args)
    {
        $entity = $args->getObject();
        $this->log($entity, LogEntity::ACTION_REMOVE);

    }

    /**
     * Post update listener
     *
     * @param LifecycleEventArgs $args
     */
    public function postUpdate(LifecycleEventArgs $args)
    {
        $entity = $args->getObject();

        $this->log($entity, LogEntity::ACTION_UPDATE);

    }

    /**
     * Saves a log
     *
     * @param LogEntity $log
     * @return bool
     */
    public function save(LogEntity $log): bool
    {
        $this->em->persist($log);
        $this->em->flush();

        return true;
    }
}

