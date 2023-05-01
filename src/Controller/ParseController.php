<?php

namespace App\Controller;

use App\Entity\Category;
use App\Entity\Creator;
use App\Form\NewsType;
use App\Repository\CategoryRepository;
use App\Repository\CreatorRepository;
use App\Entity\News;
use App\Repository\NewsRepository;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use \DOMDocument;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/parse')]
class ParseController extends AbstractController
{
    #[Route('/', name: 'news', methods: ['GET'])]
    public function index(HttpClientInterface $client, EntityManagerInterface $entityManager, Request $request): Response
    {

        $response = $client->request(
            'GET',
            'https://rss.nytimes.com/services/xml/rss/nyt/World.xml'
        );

        $dom = new DomDocument();
        $dom->loadXML($response->getContent());
        $items = $dom->getElementsByTagName('item');
        $newsArray = [];

        foreach($items as $item){
            $news = new News();
            $news->setTitle($item->getElementsByTagName('title')->item(0)->nodeValue);
            $news->setLink($item->getElementsByTagName('link')->item(0)->nodeValue);
            $news->setDescription($item->getElementsByTagName('description')->item(0)->nodeValue);
            $date_string = date("Y-m-d H:i:s",strtotime((string)$item->getElementsByTagName('pubDate')->item(0)->nodeValue));
            $dateTime = DateTime::createFromFormat('Y-m-d H:i:s', $date_string);
            $news->setPubdate($dateTime);
            if($item->getElementsByTagName('creator')->item(0)){
                $creator = new Creator();
                $creator->setName($item->getElementsByTagName('creator')->item(0)->nodeValue);
                $news->setCreator($creator);
            }
            foreach($item->getElementsByTagName('category') as $category){
                $cat = new Category();
                $cat->setName($category->nodeValue);

                $news->addCategory($cat);
            }

            $newsArray[] = $news;
        }

        //sorting
        $sortParam = $request->query->get('sort')??'asc';
        $filterByParam = $request->query->get('filterBy')??'pubDate';
        if($filterByParam === 'pubDate'){
            usort($newsArray, function(News $a, News $b){
                if($a->getPubdate() === $b->getPubdate()){
                    return 0;
                }

                return ($a->getPubdate() < $b->getPubdate()) ? -1 : 1;
            });
        }else{
            usort($newsArray, function(News $a, News $b){
                return strcmp($a->getTitle(), $b->getTitle());
            });
        }

        if($sortParam === 'desc'){
            $newsArray = array_reverse($newsArray);
        }

        //searchBar
        $searchParam = $request->query->get('search');
        $searchedNews = [];
        if($searchParam){
            foreach ($newsArray as $newsItem){
                if(stristr($newsItem->getTitle(), $searchParam) || stristr($newsItem->getDescription(), $searchParam)){
                    $searchedNews[] = $newsItem;
                }
            }
            $newsArray = $searchedNews;
        }

        return $this->render('parse/index.html.twig', [
            'news' => $newsArray,
            'sort' => $sortParam,
            'filterBy' => $filterByParam,
            'searchParam' => $searchParam,
        ]);
    }



    #[Route('/save-news', name: 'save-news', methods: ['GET'])]
    public function saveNews(Request $request, EntityManagerInterface $entityManager, CategoryRepository $categoryRepository, CreatorRepository $creatorRepository, NewsRepository $newsRepository): Response{


        $news = json_decode($request->query->get('news'), true);

        $categories = [];
        foreach ($news['category'] as $cat){
            $category = $categoryRepository->findOneBy(['name'=>$cat]);
            if(!$category){
                $category = new Category();
                $category->setName($cat);
                $entityManager->persist($category);
            }
            $categories[] = $category;
        }

        $creator = $creatorRepository->findOneBy(['name'=>$news['creator']]);
        if(!$creator){
            $creator = new Creator();
            $creator->setName($news['creator']);
            $entityManager->persist($creator);
        }

        foreach($news as $item){
            $stire = $newsRepository->findOneBy(['title'=>$item]);
            if(!$stire){
                $date_string = date("Y-m-d H:i:s",strtotime($news['pubdate']['date']));
                $pubdate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date_string);
                $newsItem = new News();
                $newsItem->setTitle($news['title']);
                $newsItem->setLink($news['link']);
                $newsItem->setDescription($news['description']);
                $newsItem->setPubdate($pubdate);
                $newsItem->setCreator($creator);
                foreach ($categories as $category){
                    $newsItem->addCategory($category);
                }
            } else{
                return new Response("Item already exists!");
            }
        }
        $entityManager->persist($newsItem);
        $entityManager->flush();

        return new Response('Success!');
    }

    #[Route('/saved-items', name: 'saved-items', methods: ['GET'])]
    public function show(NewsRepository $newsRepository): Response
    {
        return $this->render('saved-items/show.html.twig', [
            'savednews'=>$newsRepository->findAll(),
        ]);
    }
}


