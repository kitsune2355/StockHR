<?php
/* ============================================================
 *  pages/_filter_foot.php — ปิดแถบตัวกรองและโครงทั้งก้อน
 * ============================================================ */
?>
    </div><!-- /.fp-body -->

    <div class="fp-foot">
      <button type="button" id="fpClear" class="fp-clear" disabled>
        <i data-lucide="rotate-ccw" class="h-4 w-4" aria-hidden="true"></i>
        ล้างตัวกรอง
      </button>
    </div>
  </aside>

  <!-- ฉากหลังทึบตอนแถบกางบนจอเล็ก แตะแล้วปิด -->
  <div id="fpBackdrop" class="fp-backdrop" hidden></div>
</div><!-- /.fp-layout -->

<script src="<?php echo asset_url('assets/js/filter-panel.js', $ASSET_BASE); ?>"></script>