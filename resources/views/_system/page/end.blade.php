</div>
@php
$__bundleUrls = app(\Saola\Core\Services\BundleManifest::class)->bundleUrls($__context__);
@endphp
{!! '<script>window.APP_CONFIGS='.json_encode(['container'=>'#app-root','router'=>['mode'=>'history','base'=>'/','routes'=>$__helper->exportSpaRoutes($__context__)],'bundles'=>$__bundleUrls,'shared'=>(object)app(\Saola\Core\Services\ClientShare::class)->export(request()),'view'=>['contextViews'=>viewContextManager()->getContextViews($__context__),'revision'=>viewContextManager()->getContextViewRevision($__context__),'systemData'=>viewContextManager()->exportContextState($__context__)['systemData'],'ssrData'=>$__helper->exportApplicationViewData()]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG).'</script>' !!}
{!! $__helper->renderSSRBoot($__VIEW_PATH__ ?? '', $__VIEW_ID__ ?? '') !!}
@include($__system__.'partials.scripts')
</body></html>
