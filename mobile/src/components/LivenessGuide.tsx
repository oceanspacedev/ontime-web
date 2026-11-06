import { Ionicons } from '@expo/vector-icons';
import { Image } from 'expo-image';
import { Pressable, View } from 'react-native';

import { Text } from '@/components/ui';
import type { LivenessStep, LivenessStepKey } from '@/lib/api';
import { fontSize, makeStyles, radius, spacing } from '@/theme';

/** A captured camera frame. */
export interface CapturedShot {
  uri: string;
  width: number;
  height: number;
}

export type LivenessFrames = Partial<Record<LivenessStepKey, CapturedShot>>;

/** Arrow hinting the head movement of a step; the camera preview is mirrored, so it matches what the user sees. */
const STEP_ICON: Record<LivenessStepKey, 'arrow-back' | 'arrow-forward' | 'person-outline'> = {
  front: 'person-outline',
  left: 'arrow-back',
  right: 'arrow-forward',
};

/** The camera frame stays black in both color schemes, so everything here is light on dark. */
const ON_CAMERA = '#FFFFFF';

function formatCountdown(seconds: number): string {
  const minutes = Math.floor(seconds / 60);
  const rest = seconds % 60;
  return `${minutes}:${String(rest).padStart(2, '0')}`;
}

/**
 * Stepper shown over the top of the camera: which step of the challenge is next, how to move and
 * how long the challenge stays valid. When every step is captured it says so instead.
 */
export function LivenessStepCard({ steps, current, remainingSeconds }: { steps: LivenessStep[]; current: LivenessStep | null; remainingSeconds: number }) {
  const styles = useStyles();
  const index = current ? steps.findIndex((step) => step.key === current.key) : steps.length;
  const urgent = remainingSeconds <= 30;

  return (
    <View style={styles.stepCard} accessibilityLiveRegion="polite">
      <View style={styles.stepHeader}>
        <Text style={styles.stepCounter} numberOfLines={1}>
          {current ? `Langkah ${index + 1} dari ${steps.length} · ${current.label}` : 'Semua langkah selesai'}
        </Text>
        <View style={[styles.timer, urgent && styles.timerUrgent]} accessibilityLabel={`Sisa waktu ${formatCountdown(remainingSeconds)}`}>
          <Ionicons name="time-outline" size={13} color={ON_CAMERA} />
          <Text style={styles.timerText}>{formatCountdown(remainingSeconds)}</Text>
        </View>
      </View>
      {current ? (
        <View style={styles.instructionRow}>
          <Ionicons name={STEP_ICON[current.key]} size={20} color={ON_CAMERA} />
          <Text style={styles.instruction}>{current.instruction}</Text>
        </View>
      ) : (
        <Text style={styles.instruction}>Periksa fotonya, lalu kirim. Ketuk foto untuk mengulang langkah itu.</Text>
      )}
    </View>
  );
}

/**
 * One thumbnail per step along the bottom of the camera. A captured step can be tapped to retake
 * it; the step being captured now is outlined in brand red.
 */
export function LivenessThumbnails({
  steps,
  frames,
  current,
  onRetake,
  disabled,
}: {
  steps: LivenessStep[];
  frames: LivenessFrames;
  current: LivenessStep | null;
  onRetake: (key: LivenessStepKey) => void;
  disabled?: boolean;
}) {
  const styles = useStyles();

  return (
    <View style={styles.thumbRow}>
      {steps.map((step, index) => {
        const frame = frames[step.key];
        const active = current?.key === step.key;
        return (
          <Pressable
            key={step.key}
            onPress={() => onRetake(step.key)}
            disabled={!frame || disabled}
            accessibilityRole="button"
            accessibilityLabel={frame ? `Ulangi langkah ${step.label}` : `Langkah ${index + 1}: ${step.label}`}
            accessibilityState={{ disabled: !frame || disabled, selected: active }}
            style={({ pressed }) => [styles.thumb, active && styles.thumbActive, pressed && styles.thumbPressed]}>
            {frame ? (
              <>
                <Image source={{ uri: frame.uri }} style={styles.thumbImage} contentFit="cover" />
                <View style={styles.thumbRetake}>
                  <Ionicons name="refresh" size={12} color={ON_CAMERA} />
                </View>
              </>
            ) : (
              <Text style={styles.thumbIndex}>{index + 1}</Text>
            )}
            <Text style={styles.thumbLabel} numberOfLines={1}>
              {step.label}
            </Text>
          </Pressable>
        );
      })}
    </View>
  );
}

const THUMB_SIZE = 64;

const useStyles = makeStyles((c) => ({
  stepCard: {
    alignSelf: 'stretch',
    gap: 6,
    paddingHorizontal: 16,
    paddingVertical: 12,
    borderRadius: radius.lg,
    backgroundColor: 'rgba(0, 0, 0, 0.55)',
  },
  stepHeader: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  stepCounter: { flex: 1, color: ON_CAMERA, fontSize: fontSize.md, fontWeight: '700' },
  timer: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    paddingHorizontal: 8,
    paddingVertical: 3,
    borderRadius: radius.pill,
    backgroundColor: 'rgba(255, 255, 255, 0.16)',
  },
  timerUrgent: { backgroundColor: c.primary },
  timerText: { color: ON_CAMERA, fontSize: fontSize.xs, fontWeight: '700', fontVariant: ['tabular-nums'] },
  instructionRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  instruction: { flex: 1, color: 'rgba(255, 255, 255, 0.85)', fontSize: fontSize.sm, lineHeight: 19, fontWeight: '500' },

  thumbRow: { flexDirection: 'row', justifyContent: 'center', gap: spacing.md },
  thumb: {
    width: THUMB_SIZE,
    height: THUMB_SIZE + 18,
    borderRadius: radius.md,
    overflow: 'hidden',
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(0, 0, 0, 0.5)',
    borderWidth: 2,
    borderColor: 'rgba(255, 255, 255, 0.35)',
  },
  thumbActive: { borderColor: c.primary },
  thumbPressed: { opacity: 0.8 },
  thumbImage: { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0 },
  thumbRetake: {
    position: 'absolute',
    top: 4,
    right: 4,
    width: 20,
    height: 20,
    borderRadius: 10,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(0, 0, 0, 0.55)',
  },
  thumbIndex: { color: ON_CAMERA, fontSize: fontSize.lg, fontWeight: '700' },
  thumbLabel: {
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
    paddingVertical: 2,
    paddingHorizontal: 2,
    textAlign: 'center',
    color: ON_CAMERA,
    fontSize: 10,
    fontWeight: '600',
    backgroundColor: 'rgba(0, 0, 0, 0.55)',
  },
}));
