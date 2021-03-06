<?php

namespace ZEDx\Http\Controllers\Backend;

use File;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Image;
use TemplateSkeleton;
use Themes;
use Widgets;
use ZEDx\Core;
use ZEDx\Http\Controllers\Controller;
use ZEDx\Http\Requests\ThemeSetRequest;
use ZEDx\Http\Requests\ThemeUploadRequest;
use Zipper;

class ThemeController extends Controller
{
    protected $uploadedTheme;

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        $themes = Themes::frontend()->all()->sortByDesc(function ($theme, $name) {
            return $theme['is_active'] == true;
        });

        $currentTheme = $themes->shift();

        return view_backend('theme.index', compact('currentTheme', 'themes'));
    }

    /**
     * Get theme screenshot.
     *
     * @return Reponse
     */
    public function screenshot($theme)
    {
        if (!Themes::has($theme)) {
            abort(404);
        }

        $screenshot = base_path('themes').'/'.$theme.'/screenshot.png';

        if (!File::exists($screenshot)) {
            return;
        }

        return Image::make($screenshot)->response();
    }

    /**
     * Customize default theme.
     *
     * @return Reponse
     */
    public function customize()
    {
        return view_backend('theme.customize');
    }

    /**
     * Update default theme.
     *
     * @return Reponse
     */
    public function update(Request $request)
    {
        Widgets::setting("Frontend\Theme\Customize", [], $request);

        return back();
    }

    /**
     * Set a new theme.
     *
     * @return Response
     */
    public function set(ThemeSetRequest $request)
    {
        if ($request->ajax()) {
            $theme = $request->get('theme');

            return Themes::frontend()->setActive($theme);
        } else {
            abort(404);
        }
    }

    /**
     * Show the form for adding a new resource.
     *
     * @return Response
     */
    public function add()
    {
        return redirect()->route('zxadmin.theme.addWithTab', 'search');
    }

    public function recompile()
    {
        TemplateSkeleton::generateTemplatesFile();
    }

    /**
     * Show the form for adding a new resource.
     *
     * @return Response
     */
    public function addWithTab($tab)
    {
        $themes = [];
        switch ($tab) {
            case 'search':
                // code...
                break;
            /*
            case 'upload':
            # code...
            break;
             */
            case 'api':
                $themes = $this->getPaginatorFromApi();
                break;

            default:
                return redirect()->route('zxadmin.theme.addWithTab', 'search');
                break;
        }

        return view_backend('theme.add', compact('themes', 'tab'));
    }

    /**
     * Download and install a new theme.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function download($theme, Request $request)
    {
        $url = Core::API.'/theme/'.$theme;
        $json = json_decode(file_get_contents($url));
        $archive = file_get_contents(Core::API.'/'.$json->archive);
        $zipPath = storage_path().'/app/'.$theme.'_'.time().'.zip';
        $package = File::put($zipPath, $archive);
        $themeName = $this->getThemeNameFromZip($zipPath);

        $this->extract($zipPath, $themeName);

        return response()->json(['success'], 200);
    }

    /**
     * upload a new theme.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function upload(ThemeUploadRequest $request)
    {
        $uploadedTheme = $request->file('file');

        if (!$uploadedTheme->isValid()) {
            return response()->json(['error' => trans('backend.theme.invalid_zip_file')], 400);
        }

        $zipPath = $uploadedTheme->getPathname();
        $fileName = $uploadedTheme->getClientOriginalName();
        $themeName = $this->getThemeNameFromZip($zipPath);

        $this->extract($zipPath, $themeName);

        return response()->json(['success'], 200);
    }

    protected function extract($zipPath, $themeName, $delete = true)
    {
        $error = false;
        $themePath = base_path('themes').DIRECTORY_SEPARATOR.$themeName;

        umask(0);

        if (Themes::has($themeName)) {
            throw new Exception(trans('backend.theme.theme_already_exist'));
        }

        if (!File::makeDirectory($themePath, 0775, true)) {
            throw new Exception(trans('backend.theme.cant_create_theme'));
        }

        Zipper::make($zipPath)->extractTo($themePath);

        if ($delete) {
            File::delete($zipPath);
        }
    }

    protected function getThemeNameFromZip($zipPath)
    {
        $manifest = Zipper::make($zipPath)->getFileContent('zedx.json');
        if (!$manifest) {
            throw new Exception(trans("zedx.json doesn't exist!"));
        }

        $manifest = json_decode($manifest);

        return $manifest->name;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     *
     * @return Response
     */
    public function destroy($themeName)
    {
        if (!Themes::has($themeName)) {
            throw new Exception(trans('backend.theme.theme_doesnt_exist', [
                'name' => $themeName,
            ]));
        }

        $themePath = base_path('themes').DIRECTORY_SEPARATOR.$themeName;

        File::deleteDirectory($themePath);

        return redirect()->route('zxadmin.theme.index');
    }

    protected function getPaginatorFromApi()
    {
        $query = \Request::query();
        $queryBuild = http_build_query($query);
        $url = Core::API.'/theme?'.$queryBuild;
        $themes = json_decode(file_get_contents($url));
        $paginator = new Paginator($themes->data, $themes->total, 10, $themes->current_page, [
            'path'  => \Request::url(),
            'query' => $query,
        ]);

        return $paginator;
    }
}
