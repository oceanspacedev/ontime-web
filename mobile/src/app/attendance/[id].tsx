import { useQuery } from '@tanstack/react-query';
import { useLocalSearchParams } from 'expo-router';

import { AttendanceDetail } from '@/components/AttendanceDetail';
import { ErrorBanner, LoadingView, Screen } from '@/components/ui';
import { attendanceApi, errorMessage } from '@/lib/api';
import { queryKeys } from '@/lib/queryClient';

export default function AttendanceDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const attendanceId = Number(id);
  const query = useQuery({
    queryKey: queryKeys.attendance(attendanceId),
    queryFn: () => attendanceApi.show(attendanceId),
    enabled: Number.isFinite(attendanceId),
  });

  if (query.isPending) {
    return <LoadingView />;
  }
  if (query.isError) {
    return (
      <Screen>
        <ErrorBanner message={errorMessage(query.error)} onRetry={() => query.refetch()} />
      </Screen>
    );
  }
  return (
    <Screen>
      <AttendanceDetail attendance={query.data} />
    </Screen>
  );
}
