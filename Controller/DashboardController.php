<?php

namespace Tkuska\DashboardBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Doctrine\ORM\EntityManagerInterface;

use Tkuska\DashboardBundle\WidgetProvider;
use Tkuska\DashboardBundle\Entity\Widget;

/**
 * Akcja controller.
 */
class DashboardController extends Controller
{
    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var TokenStorageInterface
     */
    private $tokenStorage;

    /**
     * @var FilesystemAdapter
     */
    private $cache;

    public function __construct(EntityManagerInterface $em, TokenStorageInterface $tokenStorage)
    {
        $this->em = $em;
        $this->tokenStorage = $tokenStorage;
        $this->cache = new FilesystemAdapter();
    }

    /**
     * @Route("/dashboard/add_widget/{type}", options={"expose"=true}, name="add_widget")
     */ 
    public function addWidgetAction(WidgetProvider $provider, $type)
    {
        $widgetType = $provider->getWidgetType($type);

        $user_id = method_exists($this->getUser(), 'getId') ? $this->getUser()->getId() : null;

        $widget = new Widget();
        $widget->importConfig($widgetType);
        $widget->setUserId($user_id);

        // We just put the new widget under the existing ones
        $bottomWidget = $this->em->getRepository(Widget::class)->getBottomWidget($user_id);
        if ($bottomWidget) {
            $widget->setY($bottomWidget->getY() + $bottomWidget->getHeight());
        }

        $this->em->persist($widget);
        $this->em->flush();

        return $this->renderWidget($provider, $widget->getId());
    }

    /**
     * @Route("/dashboard/remove_widget/{id}", options={"expose"=true}, name="remove_widget")
     */
    public function removeWidgetAction($id)
    {
        $widget = $this->em->getRepository(Widget::class)->find($id);

        if ($widget && $this->isAllowed($widget)) {
            $this->em->remove($widget);
            $this->em->flush();
        }

        return new JsonResponse(true);
    }

    /**
     * @Route("/dashboard/update_widget/{id}/{x}/{y}/{width}/{height}", options={"expose"=true}, name="update_widget")
     */
    public function updateWidgetAction($id, $x, $y, $width, $height)
    {
        $widget = $this->em->getRepository(Widget::class)->find($id);

        if ($widget && $this->isAllowed($widget)) {

            $this->tryResetCache($widget);

            $widget
                ->setX($x)
                ->setY($y)
                ->setWidth($width)
                ->setHeight($height)
            ;

            $this->em->flush();
        }

        return new JsonResponse(true);
    }

    /**
     * @Route("/dashboard/update_title/{id}/{title}", options={"expose"=true}, name="update_title")
     */
    public function updateWidgetTitleAction($id, $title)
    {
        $widget = $this->em->getRepository(Widget::class)->find($id);

        if ($widget && $this->isAllowed($widget)) {

            $this->tryResetCache($widget);

            $widget->setTitle($title);
            $this->em->flush();
        }

        return new JsonResponse(true);
    }

    /**
     * @Route("/dashboard/render_widget/{id}", options={"expose"=true}, name="render_widget")
     */
    public function renderWidget(WidgetProvider $provider, $id)
    {
        $widget = $this->em->getRepository(Widget::class)->find($id);

        $response = new Response();
        $response->setContent("");
        
        if ($widget && $this->isAllowed($widget)) {
            $widgetType = $provider->getWidgetType($widget->getType());

            if ($widgetType) {
                $widgetType->setParams($widget);
                $response->setContent($widgetType->render());
            }

        }
        return $widgetType->transformResponse($response);
    }

    /**
     * @Route("/dashboard/widget_save_config/{id}", name="widget_save_config")
     */
    public function saveConfig(Request $request, WidgetProvider $provider, $id)
    {
        $config = $request->request->get("form")["json_form_".$id];
        $widget = $this->em->getRepository(Widget::class)->find($id);
        
        if ($widget && $this->isAllowed($widget)) {
            $widget->setConfig($config);
            $this->em->flush();
        }

        return $this->redirectToRoute("homepage");
    }

    /**
     * Reset config and title of widget.
     * @Route("/dashboard/widget_reset_config/{id}", name="widget_reset_config")
     */
    public function resetConfig($id)
    {
        $widget = $this->em->getRepository(Widget::class)->find($id);

        if ($widget && $this->isAllowed($widget)) {
            $widget->setTitle(null);
            $widget->setConfig(null);
            $this->em->flush();
        }

        return $this->redirectToRoute("homepage");
    }

    /**
     * Delete current user's widgets.
     * @Route("/dashboard/delete_my_widgets", name="delete_my_widgets")
     */
    public function deleteMyWidgets()
    {
        $user = $this->getUser();
        if ($user) {
            $this->em->getRepository(Widget::class)->deleteMyWidgets($user->getId());
        }

        return $this->redirectToRoute("homepage");
    }
    
    /**
     * @Route("/", name="homepage", methods="GET")
     */
    public function dashboardAction(WidgetProvider $provider)
    {
        $user = $this->getUser();
        $widget_types = $provider->getWidgetTypes();

        if ($user) {
            $widgets = $provider->getMyWidgets();

            // l'utilisateur n'a pas de widgets, on met ceux par défaut.
            if (!$widgets) {
                $provider->setDefaultWidgetsForUser($user->getId());
                $widgets = $provider->getMyWidgets();
            }
        } else {
            $widgets = [];
        }

        return $this->render("@TkuskaDashboard/dashboard/dashboard.html.twig", array(
            "widgets" => $widgets,
            "widget_types" => $widget_types,
        ));
    }

    protected function getUser()
    {
        if ($this->tokenStorage->getToken() && is_object($this->tokenStorage->getToken()->getUser())) {
            return $this->tokenStorage->getToken()->getUser();
        }

        return null;
    }

    /**
     * @param Widget $widget the widget
     * Default behaviour of AbstractWidget is caching the widgets for 5 min.
     * But when we update the widget, we want to invalidate the cache to take into account user's changes
     */
    private function tryResetCache(Widget $widget)
    {
        if (null === $widget) {
            return;
        }

        // Get associated abstract widget
        $widgetProvider = $this->get(WidgetProvider::class);
        $widgetType = $widgetProvider->getWidgetType($widget->getType())->setId($widget->getId());

        // If it's in the cache, delete it.
        if ($widgetType && $this->cache->hasItem($widgetType->getCacheKey())) {

            $this->cache->delete($widgetType->getCacheKey());
        }
    }

    /**
     * @param Widget $widget
     * @return bool if current user can do things with this widget
     */
    private function isAllowed(Widget $widget): bool
    {
        $user_id = method_exists($this->getUser(), 'getId') ? $this->getUser()->getId() : null;
        return $user_id === $widget->getUserId();
    }
}
