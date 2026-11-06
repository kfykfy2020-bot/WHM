
                </div><!-- /.main-content -->
                {if !$inShoppingCart && $secondarySidebar->hasChildren()}
                    <div class="col-md-3 pull-md-left sidebar sidebar-secondary">
                        {include file="$template/includes/sidebar.tpl" sidebar=$secondarySidebar}
                    </div>
                {/if}
            <div class="clearfix"></div>
        </div>
    </div>
</section>

{literal}<style>
#footer .footer-brand{display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap;margin:0;text-align:center;line-height:1.5}
#footer .footer-brand img{height:34px;width:auto;flex:0 0 auto}
@media (max-width:767px){
    #footer{padding-top:18px;padding-bottom:18px}
    #footer .footer-brand{gap:8px}
    #footer .footer-brand img{height:26px}
}
</style>{/literal}
<section id="footer">
    <div class="container">
        <a href="#" class="back-to-top"><i class="fas fa-chevron-up"></i></a>
        {if $templatefile == 'login' || $templatefile == 'signin'}
        <p class="footer-brand"><img src="{$WEB_ROOT}/assets/img/sb-icon.png" alt="سلاسل بابل"> <span>{lang key="copyrightFooterNotice" year=$date_year company=$companyname}</span></p>
        {else}
        <p>{lang key="copyrightFooterNotice" year=$date_year company=$companyname}</p>
        {/if}
    </div>
</section>

<div id="fullpage-overlay" class="hidden">
    <div class="outer-wrapper">
        <div class="inner-wrapper">
            <img src="{$WEB_ROOT}/assets/img/overlay-spinner.svg">
            <br>
            <span class="msg"></span>
        </div>
    </div>
</div>

<div class="modal system-modal fade" id="modalAjax" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content panel-primary">
            <div class="modal-header panel-heading">
                <button type="button" class="close" data-dismiss="modal">
                    <span aria-hidden="true">&times;</span>
                    <span class="sr-only">{$LANG.close}</span>
                </button>
                <h4 class="modal-title"></h4>
            </div>
            <div class="modal-body panel-body">
                {$LANG.loading}
            </div>
            <div class="modal-footer panel-footer">
                <div class="pull-left loader">
                    <i class="fas fa-circle-notch fa-spin"></i>
                    {$LANG.loading}
                </div>
                <button type="button" class="btn btn-default" data-dismiss="modal">
                    {$LANG.close}
                </button>
                <button type="button" class="btn btn-primary modal-submit">
                    {$LANG.submit}
                </button>
            </div>
        </div>
    </div>
</div>

{include file="$template/includes/generate-password.tpl"}

{$footeroutput}

</body>
</html>
