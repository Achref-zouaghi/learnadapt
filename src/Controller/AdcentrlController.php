<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AdcentrlController extends AbstractController
{
    #[Route('/adcentrl', name: 'app_adcentrl')]
    public function index(): Response
    {
        return $this->redirectToRoute('app_home');
    }
}
