<?php

namespace Student;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\Router\Http\Segment;
use Laminas\ServiceManager\Factory\InvokableFactory;

return [

    'router' => [
        'routes' => [
            'student' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/student[/:action[/:id]]',
                    'defaults' => [
                        'controller' => Controller\StudentController::class,
                        'action' => 'index',
                    ],
                ],
            ],
        ],
    ],

   'controllers' => [
    'factories' => [
        Controller\StudentController::class =>
            function ($container) {

                $adapter = $container->get(Adapter::class);

                $studentTableGateway = new TableGateway(
                    'studentsinfo',
                    $adapter
                );

                $userTableGateway = new TableGateway(
                    'users',
                    $adapter
                );

                return new Controller\StudentController(
                    $studentTableGateway,
                    $userTableGateway
                );
            },
    ],
],

    'service_manager' => [
        'factories' => [
            Adapter::class => function ($container) {

                $config = $container->get('config');

                return new Adapter(
                    $config['db']
                );
            },
        ],
    ],

    'view_manager' => [
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
    ],

];