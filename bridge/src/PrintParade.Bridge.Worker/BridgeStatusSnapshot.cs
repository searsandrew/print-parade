namespace PrintParade.Bridge.Worker;

public sealed record BridgeStatusSnapshot(
    string State,
    Uri? ServerUrl,
    long? BridgeId,
    DateTimeOffset? LastPollAt,
    DateTimeOffset? LastJobAt,
    int JobsProcessed,
    int WarningCount,
    DateTimeOffset UpdatedAt,
    List<string> RecentEvents);
