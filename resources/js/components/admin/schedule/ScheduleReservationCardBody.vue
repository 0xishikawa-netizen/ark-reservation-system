<script setup lang="ts">
import { statusColor, reservationStatusLabel as statusLabel } from "@/design/tokens";
import { genderClass, genderLabel, statusIcon } from "@/components/admin/schedule/scheduleFormat";
import type { ScheduleReservation } from "@/components/admin/schedule/types";
import { MESSAGES } from "@/constants/messages";

/**
 * 予約カード（ボタン）の中身。1行目＝性別・名前・状態、2行目＝新規バッジ・時間、
 * 3行目＝指名／希望バッジ・メニュー（メニューは最大2行で折り返し）。外側のボタン（位置・ドラッグ操作）は台帳ページ側が持つ。
 */
defineProps<{
    reservation: ScheduleReservation;
    /** 施術の終了時刻ラベル（終了後インターバルを除く）。 */
    endLabel: string;
    /** 終了後インターバル区間の位置指定。 */
    bufferStyle: Record<string, string>;
    /** ドラッグ中だけ出す移動先の時間ラベル（例: 10:00〜11:00）。 */
    dragLabel: string | null;
}>();
</script>

<template>
    <!-- 終了後インターバル（予約の後ろの別区間。次の予約はここから後） -->
    <span
        v-if="
            (reservation.buffer_min ??
                0) > 0
        "
        class="reservation-buffer"
        :style="
            bufferStyle
        "
        :title="
            MESSAGES.schedule.bufferSegment.replace(
                '{min}',
                String(
                    reservation.buffer_min,
                ),
            )
        "
        aria-hidden="true"
    />
    <span
        v-if="dragLabel"
        class="reservation-dragtip"
    >
        {{ dragLabel }}
    </span>

    <!-- 行1：性別・顧客名 …… 状態アイコン -->
    <span class="reservation-topline">
        <span
            class="reservation-nameline"
        >
            <span
                v-if="
                    genderLabel(
                        reservation.customer_gender,
                    )
                "
                :class="
                    genderClass(
                        reservation.customer_gender,
                    )
                "
                :title="
                    reservation.customer_gender ===
                    'male'
                        ? '男性'
                        : '女性'
                "
                >{{
                    genderLabel(
                        reservation.customer_gender,
                    )
                }}</span
            >
            <span
                class="reservation-customer"
                :title="
                    reservation.customer_name
                "
            >
                {{
                    reservation.customer_name
                }}
            </span>
        </span>
        <v-tooltip
            :text="
                statusLabel(
                    reservation.status,
                )
            "
            location="top"
        >
            <template
                #activator="{
                    props: tip,
                }"
            >
                <v-icon
                    v-bind="tip"
                    :icon="
                        statusIcon(
                            reservation.status,
                        )
                    "
                    size="15"
                    class="reservation-status-icon"
                    :style="{
                        color: `rgb(var(--v-theme-${statusColor(reservation.status)}))`,
                    }"
                    :aria-label="
                        statusLabel(
                            reservation.status,
                        )
                    "
                />
            </template>
        </v-tooltip>
    </span>

    <!-- 行2：（新規のみ）新規バッジ／時刻 -->
    <span class="reservation-timeline">
        <span v-if="reservation.is_new_customer" class="reservation-badge reservation-badge--new">新</span>
        <span class="reservation-time">{{ reservation.starts_at.slice(11, 16) }}–{{ endLabel }}</span>
    </span>

    <!-- 行3：指名／希望バッジ＋メニュー。メニュー名は最大2行まで折り返して、空いている下の段も使う。 -->
    <span class="reservation-service-line">
        <span v-if="reservation.is_staff_requested" class="reservation-badge reservation-badge--nomination">指名</span>
        <span
            v-else-if="reservation.staff_gender_preference"
            :class="`reservation-gender reservation-gender--${reservation.staff_gender_preference}`"
        >{{ reservation.staff_gender_preference === "male" ? "男希" : "女希" }}</span>
        <span class="reservation-service reservation-service--wrap" :title="reservation.service_name">{{ reservation.service_name }}</span>
    </span>
</template>

<style scoped>
/* 終了後インターバル：予約カードの右端に、施術と区別できる斜線の区間として描く（Task 11-29）。 */
.reservation-buffer {
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    pointer-events: none;
    border-left: 1px dashed rgba(15, 23, 42, 0.25);
    background: repeating-linear-gradient(
        -45deg,
        rgba(255, 255, 255, 0.85) 0 3px,
        rgba(148, 163, 184, 0.35) 3px 6px
    );
}

.reservation-dragtip {
    position: absolute;
    top: 2px;
    right: 2px;
    z-index: 2;
    padding: 1px 6px;
    border-radius: 999px;
    background: rgb(var(--v-theme-primary));
    color: #fff;
    font-size: 0.6875rem;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}

.reservation-topline {
    display: flex;
    width: 100%;
    min-width: 0;
    align-items: center;
    justify-content: space-between;
    gap: var(--ark-space-1);
}

.reservation-nameline {
    display: flex;
    align-items: center;
    gap: 3px;
    min-width: 0;
    overflow: hidden;
}

.reservation-status-icon {
    flex: 0 0 auto;
}

.reservation-timeline {
    display: flex;
    align-items: center;
    gap: 4px;
    width: 100%;
    min-width: 0;
}

.reservation-time {
    flex: 0 0 auto;
    font-size: 0.6875rem;
    font-weight: 800;
    letter-spacing: 0.01em;
}

.reservation-customer,
.reservation-service {
    display: block;
    overflow: hidden;
    width: 100%;
    min-width: 0;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.reservation-customer {
    font-size: 0.75rem;
    font-weight: 700;
}

.reservation-badges {
    display: flex;
    width: 100%;
    min-width: 0;
    align-items: center;
    gap: 4px;
}

.reservation-badge {
    flex: 0 0 auto;
    padding: 0 4px;
    border-radius: var(--ark-radius-sm);
    font-size: 0.625rem;
    font-weight: 800;
    line-height: 1.6;
    letter-spacing: 0.02em;
}

.reservation-badge--new {
    background: #d95f0e;
    color: #fff;
}

.reservation-badge--nomination {
    background: rgb(var(--v-theme-error));
    color: #fff;
}

.reservation-service-line {
    display: flex;
    align-items: flex-start;
    gap: 4px;
    width: 100%;
    min-width: 0;
}

.reservation-gender {
    flex: 0 0 auto;
    padding: 0 4px;
    border-radius: var(--ark-radius-sm);
    font-size: 0.625rem;
    font-weight: 800;
    line-height: 1.6;
}

.reservation-gender--male {
    color: rgb(var(--v-theme-info));
    background: rgba(var(--v-theme-info), 0.12);
}

.reservation-gender--female {
    color: #c2185b;
    background: rgba(194, 24, 91, 0.12);
}

.reservation-service {
    color: rgb(var(--v-theme-on-surface));
    font-size: 0.6875rem;
    opacity: 0.72;
}

.reservation-service-line .reservation-service {
    flex: 1 1 auto;
    width: auto;
    min-width: 0;
}

/* メニュー名は最大2行まで折り返す（長いメニュー名でも切れにくくする）。 */
.reservation-service--wrap {
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    overflow: hidden;
    white-space: normal;
    word-break: break-all;
    line-height: 1.3;
}

/* 予約カードは狭い画面だと文字が潰れるので、最小限の情報を優先する。 */
@media (max-width: 1023px) {
    .reservation-service-line {
        display: none;
    }
}
</style>
