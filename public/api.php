<?php

require_once '../app/controllers/SignalController.php';

// Initialize signal controller and handle request
$controller = new SignalController();
$controller->handleRequest();